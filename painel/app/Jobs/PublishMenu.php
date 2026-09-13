<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAsTenant;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Botão "Publicar": transforma o rascunho (banco) no cardápio que a mesa lê.
 *
 * menus/{slug}/v{n}.json   conteúdo, imutável, cache de 1 ano
 * menus/{slug}/current.json   ponteiro { "version": n }, nunca cacheado
 *
 * Todo o offline depende destes dois headers. Eles viajam como metadado do
 * objeto no S3/R2 (MinIO em dev), por isso o disco precisa ser s3.
 */
class PublishMenu implements ShouldQueue
{
    use Queueable, RunsAsTenant;

    /** A atual + 2 anteriores: cache seguro e rollback rápido, não histórico. */
    public const KEEP_VERSIONS = 3;

    /** Seguro só porque um v{n} nunca é reescrito: n só cresce. */
    public const VERSION_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** Nenhum cache HTTP guarda o ponteiro. O offline vive no Cache Storage do SW. */
    public const POINTER_CACHE_CONTROL = 'no-store';

    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $tenantId) {}

    public static function versionPath(string $slug, int $version): string
    {
        return "menus/{$slug}/v{$version}.json";
    }

    public static function pointerPath(string $slug): string
    {
        return "menus/{$slug}/current.json";
    }

    public function handle(): void
    {
        $this->asTenant($this->tenantId, fn () => DB::transaction(fn () => $this->publish()));
    }

    private function publish(): void
    {
        /*
         * lockForUpdate na linha do restaurante: dois "Publicar" seguidos
         * rodam um depois do outro. Sem isso, o job mais lento poderia gravar o
         * ponteiro por último e voltar o cardápio para a versão anterior.
         * O lock dura o job inteiro (poucos centésimos de segundo nesta escala).
         */
        $tenant = Tenant::query()->whereKey($this->tenantId)->lockForUpdate()->first();

        if ($tenant === null) {
            throw new RuntimeException("Restaurante [{$this->tenantId}] não encontrado (apagado ou fora da RLS).");
        }

        $disk = Storage::disk(config('filesystems.snapshot_disk'));
        $stored = $this->storedVersions($disk, $tenant->slug);

        /*
         * O maior entre banco e storage. Se uma publicação anterior gravou o
         * ponteiro e falhou antes do commit, o banco "esqueceu" aquele n, mas o
         * storage lembra, e ele nunca é reusado.
         */
        $version = max($tenant->current_version, $stored[0] ?? 0) + 1;

        $tenant->current_version = $version;
        $tenant->save();

        /* Ordem importa: conteúdo antes do ponteiro; faxina só depois do ponteiro. */
        $this->write($disk, self::versionPath($tenant->slug, $version), $this->snapshot($tenant), self::VERSION_CACHE_CONTROL);
        $this->write($disk, self::pointerPath($tenant->slug), ['version' => $version], self::POINTER_CACHE_CONTROL);
        $this->pruneOldVersions($disk, $tenant->slug);
    }

    /**
     * Campos listados um a um: o snapshot é público, e é o contrato de
     * cardapio/src/types/menu.ts. Coluna nova no banco não vaza sozinha
     * (image_path, tenant_id, timestamps ficam de fora).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Tenant $tenant): array
    {
        $categories = $tenant->categories()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->get();

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'categories' => $categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
                'items' => $category->items->map(fn (Item $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'price_cents' => $item->price_cents,
                    'image_url' => $item->image_url,
                    'featured' => $item->featured,
                    'available' => $item->available,
                    'sort_order' => $item->sort_order,
                ])->all(),
            ])->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function write(Filesystem $disk, string $path, array $data, string $cacheControl): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $stored = $disk->put($path, $json, [
            'CacheControl' => $cacheControl,
            'ContentType' => self::CONTENT_TYPE,
        ]);

        if (! $stored) {
            throw new RuntimeException("Falha ao gravar [{$path}] no storage de snapshots.");
        }
    }

    /** Apaga tudo além das KEEP_VERSIONS versões mais novas. */
    private function pruneOldVersions(Filesystem $disk, string $slug): void
    {
        $expired = array_slice($this->storedVersions($disk, $slug), self::KEEP_VERSIONS);

        if ($expired === []) {
            return;
        }

        $deleted = $disk->delete(array_map(fn (int $version): string => self::versionPath($slug, $version), $expired));

        if (! $deleted) {
            throw new RuntimeException("Falha ao apagar versões antigas de [{$slug}].");
        }
    }

    /**
     * Números das versões gravadas, da mais nova para a mais antiga.
     *
     * @return list<int>
     */
    private function storedVersions(Filesystem $disk, string $slug): array
    {
        $versions = [];

        foreach ($disk->files("menus/{$slug}") as $path) {
            if (preg_match('#/v(\d+)\.json$#', $path, $match)) {
                $versions[] = (int) $match[1];
            }
        }

        rsort($versions);

        return $versions;
    }
}

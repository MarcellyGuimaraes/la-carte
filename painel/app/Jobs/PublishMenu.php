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
use Throwable;

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
        $slug = $this->asTenant($this->tenantId, fn () => DB::transaction(fn () => $this->publish()));

        /*
         * Faxina FORA da transação e sem derrubar o job: nesse ponto o cardápio
         * novo já está no ar. Se ela lançasse, a transação desfaria o
         * current_version de um cardápio publicado e o retry publicaria versões
         * extras. Sobrou arquivo? A próxima publicação limpa.
         */
        try {
            $this->pruneOldVersions(Storage::disk(config('filesystems.snapshot_disk')), $slug);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return string o slug publicado, para a faxina. */
    private function publish(): string
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
        $snapshot = $this->snapshot($tenant);
        $published = $this->publishedVersion($disk, $tenant->slug);

        /*
         * Idempotência: nada mudou desde a versão no ar, então não há o que
         * publicar. Um retry depois de sucesso vira no-op, e "Publicar" três
         * vezes seguidas não empurra as versões de rollback para fora.
         */
        if ($published !== null && $this->sameContent($published['menu'], $snapshot)) {
            /* Conserta banco que ficou para trás (ponteiro gravado, commit perdido). */
            if ($tenant->current_version < $published['version']) {
                $tenant->current_version = $published['version'];
                $tenant->save();
            }

            return $tenant->slug;
        }

        $stored = $this->storedVersions($disk, $tenant->slug);

        /*
         * O maior entre banco e storage. Se uma publicação anterior gravou o
         * ponteiro e falhou antes do commit, o banco "esqueceu" aquele n, mas o
         * storage lembra, e ele nunca é reusado.
         */
        $version = max($tenant->current_version, $stored[0] ?? 0) + 1;

        $tenant->current_version = $version;
        $tenant->save();

        /*
         * Ordem importa: conteúdo gravado E conferido antes do ponteiro. Se
         * qualquer passo lançar, o ponteiro continua na versão anterior, que
         * está inteira, e a transação desfaz o current_version.
         */
        $this->write($disk, self::versionPath($tenant->slug, $version), $snapshot, self::VERSION_CACHE_CONTROL);
        $this->write($disk, self::pointerPath($tenant->slug), ['version' => $version], self::POINTER_CACHE_CONTROL);

        return $tenant->slug;
    }

    /**
     * A versão para a qual o current.json aponta, se existir e for legível.
     *
     * @return array{version: int, menu: array<string, mixed>}|null
     */
    private function publishedVersion(Filesystem $disk, string $slug): ?array
    {
        $pointer = $this->readJson($disk, self::pointerPath($slug));
        $version = is_array($pointer) ? ($pointer['version'] ?? null) : null;

        if (! is_int($version)) {
            return null;
        }

        $menu = $this->readJson($disk, self::versionPath($slug, $version));

        return is_array($menu) ? ['version' => $version, 'menu' => $menu] : null;
    }

    /**
     * null se o arquivo não existe ou não é JSON. exists() antes do get(): com
     * o disco configurado para lançar erro, ler arquivo ausente estouraria na
     * primeira publicação de um restaurante.
     */
    private function readJson(Filesystem $disk, string $path): mixed
    {
        return $disk->exists($path) ? json_decode((string) $disk->get($path), true) : null;
    }

    /**
     * Mesmo cardápio, ignorando generated_at (muda a cada geração).
     *
     * @param  array<string, mixed>  $published
     * @param  array<string, mixed>  $draft
     */
    private function sameContent(array $published, array $draft): bool
    {
        unset($published['generated_at'], $draft['generated_at']);

        return json_encode($published) === json_encode($draft);
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

        /*
         * Relê o que gravou. "put deu certo" não prova que o arquivo está
         * inteiro, e um v{n} corrompido com cache imutável de 1 ano não tem
         * conserto no celular. Um GET por publicação é barato.
         */
        if ($disk->get($path) !== $json) {
            throw new RuntimeException("Conteúdo gravado em [{$path}] não confere com o gerado.");
        }
    }

    /**
     * Apaga tudo além das KEEP_VERSIONS versões mais novas, e nunca a versão
     * para a qual o current.json aponta.
     */
    private function pruneOldVersions(Filesystem $disk, string $slug): void
    {
        $pointed = $this->publishedVersion($disk, $slug)['version'] ?? null;

        $expired = array_values(array_filter(
            array_slice($this->storedVersions($disk, $slug), self::KEEP_VERSIONS),
            fn (int $version): bool => $version !== $pointed,
        ));

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

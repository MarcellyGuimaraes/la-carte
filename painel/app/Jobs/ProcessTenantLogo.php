<?php

namespace App\Jobs;

use App\Jobs\Concerns\ConvertsToWebp;
use App\Jobs\Concerns\RunsAsTenant;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transforma o logo enviado pelo dono em WebP e grava a URL final no tenant.
 *
 * Mesmo desenho do ProcessItemImage (ids em vez de model, RunsAsTenant, update
 * condicional), com uma diferença: um tamanho só. O logo aparece pequeno no
 * cabeçalho da mesa e no painel; 512px cobre tela 3x com folga, e uma URL é
 * tudo o que o snapshot referencia.
 */
class ProcessTenantLogo implements ShouldQueue
{
    use ConvertsToWebp, Queueable, RunsAsTenant;

    public const WIDTH = 512;

    /** Retentar ajuda em falha de storage; imagem ilegível falha na hora. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $tenantId,
        public readonly string $logoPath,
    ) {}

    public function handle(): void
    {
        $this->asTenant($this->tenantId, fn () => $this->process());
    }

    private function process(): void
    {
        /* O dono trocou ou removeu o logo enquanto o job esperava na fila. */
        if (! $this->logoIsStillCurrent()) {
            return;
        }

        $bytes = Storage::disk('public')->get($this->logoPath);

        if ($bytes === null) {
            throw new RuntimeException("Logo original não encontrado: [{$this->logoPath}].");
        }

        $source = $this->decodeImage($bytes);

        if ($source === null) {
            /* Arquivo corrompido não melhora tentando de novo: falha sem retry. */
            $this->fail(new RuntimeException("Arquivo não é uma imagem legível: [{$this->logoPath}]."));

            return;
        }

        $path = self::variantPath($this->logoPath, self::WIDTH);
        $disk = Storage::disk(config('filesystems.media_disk'));

        if (! $disk->put($path, $this->toWebp($source, self::WIDTH, $this->logoPath), 'public')) {
            throw new RuntimeException("Falha ao gravar [{$path}] no storage de mídia.");
        }

        /*
         * Update condicional e sem eventos do model, como no item: logo trocado
         * durante a conversão não pode ser sobrescrito por este job.
         */
        Tenant::query()
            ->whereKey($this->tenantId)
            ->where('logo_path', $this->logoPath)
            ->update(['logo_url' => $disk->url($path)]);
    }

    private function logoIsStillCurrent(): bool
    {
        return Tenant::query()
            ->whereKey($this->tenantId)
            ->where('logo_path', $this->logoPath)
            ->exists();
    }
}

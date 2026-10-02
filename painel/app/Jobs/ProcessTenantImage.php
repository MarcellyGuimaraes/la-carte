<?php

namespace App\Jobs;

use App\Enums\TenantImage;
use App\Jobs\Concerns\ConvertsToWebp;
use App\Jobs\Concerns\RunsAsTenant;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transforma uma imagem de marca (logo ou capa) em WebP e grava a URL final no
 * tenant.
 *
 * Mesmo desenho do ProcessItemImage (ids em vez de model, RunsAsTenant, update
 * condicional), com uma diferença: um tamanho só, o do slot (TenantImage).
 */
class ProcessTenantImage implements ShouldQueue
{
    use ConvertsToWebp, Queueable, RunsAsTenant;

    /** Retentar ajuda em falha de storage; imagem ilegível falha na hora. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $tenantId,
        public readonly TenantImage $image,
        public readonly string $path,
    ) {}

    public function handle(): void
    {
        $this->asTenant($this->tenantId, fn () => $this->process());
    }

    private function process(): void
    {
        /* O dono trocou ou removeu a imagem enquanto o job esperava na fila. */
        if (! $this->isStillCurrent()) {
            return;
        }

        $bytes = Storage::disk('public')->get($this->path);

        if ($bytes === null) {
            throw new RuntimeException("Original não encontrado: [{$this->path}].");
        }

        $source = $this->decodeImage($bytes);

        if ($source === null) {
            /* Arquivo corrompido não melhora tentando de novo: falha sem retry. */
            $this->fail(new RuntimeException("Arquivo não é uma imagem legível: [{$this->path}]."));

            return;
        }

        $width = $this->image->width();
        $variant = self::variantPath($this->path, $width);
        $disk = Storage::disk(config('filesystems.media_disk'));

        if (! $disk->put($variant, $this->toWebp($source, $width, $this->path), 'public')) {
            throw new RuntimeException("Falha ao gravar [{$variant}] no storage de mídia.");
        }

        /*
         * Update condicional e sem eventos do model, como no item: imagem
         * trocada durante a conversão não pode ser sobrescrita por este job.
         */
        $updated = Tenant::query()
            ->whereKey($this->tenantId)
            ->where($this->image->pathColumn(), $this->path)
            ->update([$this->image->urlColumn() => $disk->url($variant)]);

        /*
         * O WebP está pronto: agora sim a imagem nova vai para a mesa (o
         * Tenant::saved deixou essa publicação para cá). 0 linhas = a imagem foi
         * trocada no meio; o job da nova publica.
         */
        if ($updated === 1) {
            dispatch(PublishMenu::branding($this->tenantId));
        }
    }

    private function isStillCurrent(): bool
    {
        return Tenant::query()
            ->whereKey($this->tenantId)
            ->where($this->image->pathColumn(), $this->path)
            ->exists();
    }
}

<?php

namespace App\Jobs;

use App\Jobs\Concerns\ConvertsToWebp;
use App\Jobs\Concerns\RunsAsTenant;
use App\Models\Item;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transforma a foto enviada pelo dono em WebP de 3 larguras e grava a URL final
 * no item.
 *
 * Roda no worker, sem request: o tenant é definido por RunsAsTenant.
 *
 * Recebe ids em vez do model: SerializesModels recarregaria o Item antes do
 * handle(), ainda sem tenant, e a RLS devolveria "não encontrado".
 */
class ProcessItemImage implements ShouldQueue
{
    use ConvertsToWebp, Queueable, RunsAsTenant;

    /** 400 para a lista, 800 é o padrão (image_url), 1200 para tela grande. */
    public const WIDTHS = [400, 800, 1200];

    public const DEFAULT_WIDTH = 800;

    /** Retentar ajuda em falha de storage; imagem ilegível falha na hora. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $itemId,
        public readonly string $imagePath,
    ) {}

    public function handle(): void
    {
        $this->asTenant($this->tenantId, fn () => $this->process());
    }

    private function process(): void
    {
        /*
         * O dono pode ter trocado a foto (ou apagado o item) enquanto o job
         * esperava na fila. Nesse caso existe outro job para a foto nova, e
         * este não tem mais o que fazer.
         */
        if (! $this->photoIsStillCurrent()) {
            return;
        }

        $source = $this->readSource();

        if ($source === null) {
            /* Arquivo corrompido não melhora tentando de novo: falha sem retry. */
            $this->fail(new RuntimeException("Arquivo não é uma imagem legível: [{$this->imagePath}]."));

            return;
        }

        foreach (self::WIDTHS as $width) {
            $this->store(self::variantPath($this->imagePath, $width), $this->toWebp($source, $width, $this->imagePath));
        }

        $url = Storage::disk(config('filesystems.media_disk'))
            ->url(self::variantPath($this->imagePath, self::DEFAULT_WIDTH));

        /*
         * Update condicional: se a foto mudou durante a conversão, a URL deste
         * job não pode vencer a do job mais novo. Query builder, sem eventos do
         * model, para não disparar outro job.
         */
        Item::query()
            ->whereKey($this->itemId)
            ->where('image_path', $this->imagePath)
            ->update(['image_url' => $url]);
    }

    private function photoIsStillCurrent(): bool
    {
        return Item::query()
            ->whereKey($this->itemId)
            ->where('image_path', $this->imagePath)
            ->exists();
    }

    /** null = os bytes existem mas não são imagem. */
    private function readSource(): ?GdImage
    {
        /* O original vive onde o FileUpload grava. */
        $bytes = Storage::disk('public')->get($this->imagePath);

        if ($bytes === null) {
            throw new RuntimeException("Foto original não encontrada: [{$this->imagePath}].");
        }

        return $this->decodeImage($bytes);
    }

    private function store(string $path, string $contents): void
    {
        $stored = Storage::disk(config('filesystems.media_disk'))->put($path, $contents, 'public');

        if (! $stored) {
            throw new RuntimeException("Falha ao gravar [{$path}] no storage de mídia.");
        }
    }
}

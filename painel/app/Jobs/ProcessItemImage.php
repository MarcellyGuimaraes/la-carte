<?php

namespace App\Jobs;

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
    use Queueable, RunsAsTenant;

    /** 400 para a lista, 800 é o padrão (image_url), 1200 para tela grande. */
    public const WIDTHS = [400, 800, 1200];

    public const DEFAULT_WIDTH = 800;

    /** 80 é o ponto em que o WebP para de ganhar tamanho sem perda visível. */
    private const QUALITY = 80;

    /** Retentar ajuda em falha de storage; imagem ilegível falha na hora. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $itemId,
        public readonly string $imagePath,
    ) {}

    /** Onde fica cada tamanho: foto.jpg vira foto-400.webp, foto-800.webp... */
    public static function variantPath(string $imagePath, int $width): string
    {
        $info = pathinfo($imagePath);
        $directory = $info['dirname'] === '.' ? '' : $info['dirname'].'/';

        return "{$directory}{$info['filename']}-{$width}.webp";
    }

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
            $this->store(self::variantPath($this->imagePath, $width), $this->toWebp($source, $width));
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

        /* @ porque o GD avisa com warning; o erro explícito é o null. */
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        /* PNG com paleta não vira WebP: o GD exige truecolor. */
        imagepalettetotruecolor($image);

        return $image;
    }

    /** Nunca amplia: foto pequena fica no tamanho dela, só muda de formato. */
    private function toWebp(GdImage $source, int $width): string
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = min($width, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * $targetWidth / $sourceWidth));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        /* Mantém a transparência de PNG. */
        imagealphablending($target, false);
        imagesavealpha($target, true);
        /* resampled em vez de imagescale: reduz com qualidade bem melhor. */
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        $ok = imagewebp($target, null, self::QUALITY);
        $webp = ob_get_clean();

        if (! $ok || $webp === false || $webp === '') {
            throw new RuntimeException("Falha ao gerar WebP de {$width}px para [{$this->imagePath}].");
        }

        return $webp;
    }

    private function store(string $path, string $contents): void
    {
        $stored = Storage::disk(config('filesystems.media_disk'))->put($path, $contents, 'public');

        if (! $stored) {
            throw new RuntimeException("Falha ao gravar [{$path}] no storage de mídia.");
        }
    }
}

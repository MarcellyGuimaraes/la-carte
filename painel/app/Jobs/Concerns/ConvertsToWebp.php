<?php

namespace App\Jobs\Concerns;

use GdImage;
use RuntimeException;

/**
 * Conversão para WebP, comum à foto do item e ao logo do restaurante.
 *
 * Só a parte de imagem: de onde ler, onde gravar e qual registro atualizar
 * continua em cada job.
 */
trait ConvertsToWebp
{
    /** 80 é o ponto em que o WebP para de ganhar tamanho sem perda visível. */
    private const WEBP_QUALITY = 80;

    /** Onde fica cada tamanho: foto.jpg vira foto-400.webp, foto-800.webp... */
    public static function variantPath(string $imagePath, int $width): string
    {
        $info = pathinfo($imagePath);
        $directory = $info['dirname'] === '.' ? '' : $info['dirname'].'/';

        return "{$directory}{$info['filename']}-{$width}.webp";
    }

    /** null = os bytes existem mas não são imagem. */
    private function decodeImage(string $bytes): ?GdImage
    {
        /* @ porque o GD avisa com warning; o erro explícito é o null. */
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        /* PNG com paleta não vira WebP: o GD exige truecolor. */
        imagepalettetotruecolor($image);

        return $image;
    }

    /** Nunca amplia: imagem pequena fica no tamanho dela, só muda de formato. */
    private function toWebp(GdImage $source, int $width, string $label): string
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = min($width, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * $targetWidth / $sourceWidth));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        /* Mantém a transparência de PNG (logo quase sempre tem). */
        imagealphablending($target, false);
        imagesavealpha($target, true);
        /* resampled em vez de imagescale: reduz com qualidade bem melhor. */
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        $ok = imagewebp($target, null, self::WEBP_QUALITY);
        $webp = ob_get_clean();

        if (! $ok || $webp === false || $webp === '') {
            throw new RuntimeException("Falha ao gerar WebP de {$width}px para [{$label}].");
        }

        return $webp;
    }
}

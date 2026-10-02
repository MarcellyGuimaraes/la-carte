<?php

namespace App\Enums;

/**
 * As imagens de marca do restaurante: cada uma é um par de colunas em tenants
 * (original enviado + URL do WebP) e uma largura.
 *
 * Um enum em vez de um job por imagem: logo e capa são o mesmo problema, e
 * dois jobs quase iguais divergiriam com o tempo (ver ProcessTenantImage).
 */
enum TenantImage: string
{
    case Logo = 'logo';
    case Cover = 'cover';

    /** Coluna com o caminho do original, no disco 'public'. */
    public function pathColumn(): string
    {
        return "{$this->value}_path";
    }

    /** Coluna com a URL final do WebP; é o que vai para o snapshot. */
    public function urlColumn(): string
    {
        return "{$this->value}_url";
    }

    /**
     * Uma largura só, porque o snapshot referencia uma URL.
     * Logo: aparece pequeno, 512px cobre tela 3x. Capa: ocupa a largura do
     * layout (34rem, ~544px CSS), 1200px cobre tela 2x com folga.
     */
    public function width(): int
    {
        return match ($this) {
            self::Logo => 512,
            self::Cover => 1200,
        };
    }

    /** Pasta do upload: {diretório}/{tenant_id}/arquivo. */
    public function directory(): string
    {
        return match ($this) {
            self::Logo => 'logos',
            self::Cover => 'covers',
        };
    }
}

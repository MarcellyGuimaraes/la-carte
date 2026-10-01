<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Paleta do cardápio na mesa. Os valores viajam no snapshot e a PWA tem uma
 * paleta pronta para cada um (cardapio/src/index.css).
 *
 * O banco aceita só estes valores (CHECK tenants_theme_valid).
 */
enum Theme: string implements HasLabel
{
    case Dark = 'dark';
    case Light = 'light';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dark => 'Escuro',
            self::Light => 'Claro',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Plano do restaurante. Cobrança ainda é manual: o plano só registra o combinado.
 *
 * O valor fica em inglês no banco; o rótulo em português é só para a tela.
 */
enum Plan: string implements HasLabel
{
    case Free = 'free';
    case Pro = 'pro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Free => 'Grátis',
            self::Pro => 'Pro',
        };
    }
}

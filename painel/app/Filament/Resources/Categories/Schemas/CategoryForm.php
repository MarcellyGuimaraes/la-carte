<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Formulário de categoria. Rótulos em português, campos em inglês.
 *
 * Sem campo de restaurante: a tenancy do Filament preenche o tenant_id.
 */
class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label('Ordem')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->helperText('Menor aparece primeiro no cardápio.'),
            ]);
    }
}

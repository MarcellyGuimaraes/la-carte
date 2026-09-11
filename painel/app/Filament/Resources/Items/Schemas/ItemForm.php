<?php

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Formulário de item do cardápio.
 *
 * Rótulos em português porque quem preenche é o dono do bar. Os nomes de
 * campo continuam em inglês, iguais às colunas e ao contrato JSON.
 */
class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->label('Restaurante')
                    ->relationship('tenant', 'name')
                    ->required(),
                Select::make('category_id')
                    ->label('Categoria')
                    ->relationship('category', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3)
                    ->columnSpanFull(),
                /*
                 * O banco guarda centavos, mas ninguém digita 1200 para R$ 12,00.
                 * A conversão vive só aqui, na borda: o model continua inteiro.
                 */
                TextInput::make('price_cents')
                    ->label('Preço')
                    ->prefix('R$')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : number_format($state / 100, 2, '.', ''),
                    )
                    ->dehydrateStateUsing(
                        fn (?string $state): int => (int) round(
                            ((float) str_replace(',', '.', (string) $state)) * 100,
                        ),
                    ),
                FileUpload::make('image_url')
                    ->label('Foto')
                    ->image()
                    ->disk('public')
                    ->directory('itens')
                    ->maxSize(4096)
                    ->helperText('Some com a foto se o prato não tiver uma boa. Foto ruim vende menos que nenhuma.'),
                Toggle::make('featured')
                    ->label('Em destaque'),
                Toggle::make('available')
                    ->label('Disponível')
                    ->default(true)
                    ->helperText('Desligue quando acabar no dia, sem precisar apagar o item.'),
                TextInput::make('sort_order')
                    ->label('Ordem')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->helperText('Menor aparece primeiro dentro da categoria.'),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Items\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /* A mesma ordem que o cliente vê na mesa. */
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Foto')
                    ->disk('public'),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->sortable(),
                /* Centavos viram reais só na exibição. */
                TextColumn::make('price_cents')
                    ->label('Preço')
                    ->sortable()
                    ->formatStateUsing(
                        fn (int $state): string => 'R$ '.number_format($state / 100, 2, ',', '.'),
                    ),
                IconColumn::make('available')
                    ->label('Disponível')
                    ->boolean(),
                IconColumn::make('featured')
                    ->label('Destaque')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tenant.name')
                    ->label('Restaurante')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->relationship('category', 'name'),
                /* O que o dono mais procura: o que está fora do ar hoje. */
                TernaryFilter::make('available')
                    ->label('Disponibilidade')
                    ->trueLabel('Só disponíveis')
                    ->falseLabel('Só indisponíveis'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Plataforma\Resources\Tenants\Tables;

use App\Enums\Plan;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Lista de todos os restaurantes.
 *
 * Sem ação de apagar, de propósito: apagar leva o cardápio junto (cascade).
 * No MVP, cliente que sai é desativado.
 */
class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('users.email')
                    ->label('Dono'),
                TextColumn::make('plan')
                    ->label('Plano')
                    ->badge(),
                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
                /* Conta os itens sem uma consulta por linha. */
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Situação')
                    ->trueLabel('Só ativos')
                    ->falseLabel('Só desativados'),
                SelectFilter::make('plan')
                    ->label('Plano')
                    ->options(Plan::class),
            ])
            ->recordActions([
                EditAction::make(),
                /* Com confirmação: desativar tira o dono do painel na hora. */
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('O dono deixa de acessar o painel. O cardápio não é apagado.')
                    ->visible(fn (Tenant $record): bool => $record->active)
                    ->action(fn (Tenant $record) => $record->update(['active' => false])),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Tenant $record): bool => ! $record->active)
                    ->action(fn (Tenant $record) => $record->update(['active' => true])),
            ]);
    }
}

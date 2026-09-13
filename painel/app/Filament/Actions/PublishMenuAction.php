<?php

namespace App\Filament\Actions;

use App\Jobs\PublishMenu;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * O botão "Publicar". Salvar no painel só mexe no rascunho; isto é o que leva
 * o cardápio para a mesa.
 *
 * Só enfileira: o trabalho pesado e a escrita no storage ficam no job.
 */
class PublishMenuAction
{
    public static function make(): Action
    {
        return Action::make('publishMenu')
            ->label('Publicar cardápio')
            ->icon('heroicon-o-cloud-arrow-up')
            ->requiresConfirmation()
            ->modalHeading('Publicar cardápio?')
            ->modalDescription(function (): string {
                /** @var Tenant $tenant */
                $tenant = Filament::getTenant();

                $current = $tenant->current_version === 0
                    ? 'Este cardápio ainda não foi publicado.'
                    : "Versão no ar: {$tenant->current_version}.";

                return "{$current} Tudo o que está salvo agora vai para as mesas, inclusive o que estiver pela metade.";
            })
            ->modalSubmitActionLabel('Publicar')
            ->action(function (): void {
                /* Tenant vindo do painel: já passou pelo IdentifyTenant e pela RLS. */
                PublishMenu::dispatch(Filament::getTenant()->getKey());

                Notification::make()
                    ->title('Publicação enviada')
                    ->body('O cardápio das mesas atualiza em alguns segundos.')
                    ->success()
                    ->send();
            });
    }
}

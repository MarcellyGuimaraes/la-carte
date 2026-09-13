<?php

namespace App\Providers\Filament;

use App\Http\Middleware\UsePlatformConnection;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Painel da dona do SaaS: onboarding e gestão de todos os restaurantes.
 *
 * Diferenças para o /admin, todas de propósito:
 * - sem ->tenant(): não há restaurante atual, a dona vê todos;
 * - resources em app/Filament/Plataforma, fora da pasta que o /admin descobre;
 * - UsePlatformConnection em vez de SetPostgresTenant: papel com BYPASSRLS.
 */
class PlataformaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('plataforma')
            ->path('plataforma')
            ->login()
            ->brandName('La Carte · Plataforma')
            /* Cor diferente do /admin para ninguém confundir onde está. */
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Plataforma/Resources'), for: 'App\Filament\Plataforma\Resources')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                /*
                 * A ordem é a trava: Authenticate barra com 403 quem não é
                 * super-admin ANTES de a conexão com bypass entrar em cena.
                 */
                Authenticate::class,
                /* Persistente: vale também nas ações do Livewire. */
                UsePlatformConnection::class,
            ], isPersistent: true);
    }
}

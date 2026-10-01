<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditBranding;
use App\Http\Middleware\ApplyTenantBranding;
use App\Http\Middleware\SetPostgresTenant;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            /*
             * Scoping do painel: URL /admin/{slug}, queries dos resources
             * filtradas e tenant_id preenchido ao criar. É a primeira barreira;
             * a RLS no Postgres é a última.
             */
            ->tenant(Tenant::class, slugAttribute: 'slug')
            /* Whitelabel: cor, tema e logo. Vira "Marca do restaurante" no menu do tenant. */
            ->tenantProfile(EditBranding::class)
            /*
             * Logo do restaurante no topo do painel; sem logo, o nome. Closure
             * porque o tenant só existe na request (avaliada na renderização).
             */
            ->brandLogo(fn (): ?string => Filament::getTenant()?->logo_url)
            ->brandLogoHeight('2.5rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
                Authenticate::class,
                /*
                 * Persistente para valer também nas requests do Livewire
                 * (salvar, filtrar, paginar), não só no carregamento da página.
                 */
                SetPostgresTenant::class,
            ], isPersistent: true)
            /* Depois do IdentifyTenant: só aí se sabe a cor do restaurante. */
            ->tenantMiddleware([
                ApplyTenantBranding::class,
            ], isPersistent: true);
    }
}

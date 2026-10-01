<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pinta o /admin com a cor do restaurante.
 *
 * Não dá para fazer isso em ->colors() do painel: o Filament registra as cores
 * no boot do painel, antes de saber qual é o restaurante (o IdentifyTenant
 * roda depois). Aqui, como middleware de tenant, o restaurante já é conhecido.
 *
 * Usa o rascunho (banco), não o publicado: o dono vê a cor nova na hora.
 * Sem cor escolhida, fica o padrão do painel.
 */
class ApplyTenantBranding
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant();

        if ($tenant?->brand_color !== null) {
            FilamentColor::register(['primary' => Color::hex($tenant->brand_color)]);
        }

        return $next($request);
    }
}

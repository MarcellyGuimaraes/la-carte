<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faz o painel /plataforma usar o papel com BYPASSRLS, que enxerga todos os
 * restaurantes.
 *
 * Registrado SÓ no PlataformaPanelProvider, e depois do Authenticate: quem não
 * passou em canAccessPanel('plataforma') recebe 403 antes de chegar aqui, e
 * nunca troca de conexão.
 *
 * A troca vale só para esta request. O fim da request volta a conexão padrão,
 * o que importa em processo que atende várias requests seguidas (testes, e
 * Octane se um dia entrar).
 *
 * Atenção ao passo 6: models carregados aqui guardam a conexão pgsql_platform.
 * Se forem serializados num job, o worker os recarrega com bypass. Jobs
 * disparados daqui recebem ids, não models.
 */
class UsePlatformConnection
{
    public const CONNECTION = 'pgsql_platform';

    public function handle(Request $request, Closure $next): Response
    {
        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection(self::CONNECTION);

        /*
         * Não é finally pelo mesmo motivo do SetPostgresTenant: o Livewire
         * reexecuta este middleware com um "next" vazio antes do componente.
         */
        app()->terminating(fn () => DB::setDefaultConnection($previous));

        return $next($request);
    }
}

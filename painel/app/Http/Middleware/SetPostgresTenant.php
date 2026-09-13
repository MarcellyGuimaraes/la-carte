<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diz ao Postgres qual é o restaurante desta request, para a RLS filtrar.
 *
 * O tenant vem do tenant_id do usuário autenticado, não da URL: é um dado do
 * banco que ninguém manipula trocando o slug no endereço. Usuário sem tenant
 * (administrador da plataforma) não define nenhum e, por design, vê zero linhas
 * aqui no /admin.
 *
 * Precisa rodar depois do Authenticate e antes do IdentifyTenant do Filament,
 * porque a busca do tenant pelo slug já passa pela RLS.
 *
 * Atenção em produção: variável de sessão não combina com PgBouncer em modo
 * transaction pooling (o valor vazaria entre clientes). Nesta escala não há
 * pooler; se entrar um, use session pooling ou troque para SET LOCAL.
 */
class SetPostgresTenant
{
    /** Conexões que ignoram a RLS. Nenhuma pode atender o /admin. */
    private const PRIVILEGED_CONNECTIONS = ['pgsql_platform', 'pgsql_owner'];

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Trava contra o bypass do /plataforma vazar para cá: se a conexão
         * padrão for uma que ignora a RLS, definir tenant não serviria de nada
         * e o dono veria todos os restaurantes. Melhor quebrar alto.
         */
        $connection = DB::getDefaultConnection();

        if (in_array($connection, self::PRIVILEGED_CONNECTIONS, true)) {
            throw new LogicException(
                "Painel do restaurante atendido pela conexão [{$connection}], que ignora a RLS.",
            );
        }

        /*
         * set_config em vez de "SET app.current_tenant = X": SET não aceita
         * parâmetro, e montar SQL concatenando valor é hábito que não queremos.
         * Sempre define, mesmo vazio, para nunca herdar valor de uso anterior
         * da conexão.
         */
        $this->setTenant($request->user()?->tenant_id);

        /*
         * O reset não pode ficar num finally aqui: o Livewire reexecuta este
         * middleware com um "next" vazio antes de rodar o componente, e um
         * finally apagaria o tenant antes das queries. terminating() roda no
         * fim de verdade da request.
         */
        app()->terminating(fn () => $this->setTenant(null));

        return $next($request);
    }

    private function setTenant(?int $tenantId): void
    {
        DB::select(
            "select set_config('app.current_tenant', ?, false)",
            [$tenantId === null ? '' : (string) $tenantId],
        );
    }
}

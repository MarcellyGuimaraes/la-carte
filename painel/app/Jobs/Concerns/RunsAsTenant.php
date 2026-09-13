<?php

namespace App\Jobs\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * O equivalente do SetPostgresTenant para jobs.
 *
 * O worker roda fora de qualquer request, então ninguém disse ao Postgres qual
 * é o restaurante e a RLS esconde tudo. O job recebe o tenant_id do registro,
 * no servidor (nunca de input do usuário), e o define só enquanto trabalha.
 */
trait RunsAsTenant
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    protected function asTenant(int $tenantId, Closure $work): mixed
    {
        /*
         * Restaura o valor anterior em vez de zerar: com QUEUE_CONNECTION=sync
         * o job roda dentro da request, e zerar apagaria o tenant dela. No
         * worker o anterior é vazio, então nada vaza para o próximo job.
         */
        $previous = DB::selectOne(
            "select coalesce(current_setting('app.current_tenant', true), '') as tenant",
        )->tenant;

        $this->setPostgresTenant((string) $tenantId);

        try {
            return $work();
        } finally {
            $this->setPostgresTenant($previous);
        }
    }

    private function setPostgresTenant(string $tenantId): void
    {
        DB::select("select set_config('app.current_tenant', ?, false)", [$tenantId]);
    }
}

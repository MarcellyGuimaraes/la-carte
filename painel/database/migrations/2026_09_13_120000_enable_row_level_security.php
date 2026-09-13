<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security: a última trava de isolamento entre restaurantes.
 *
 * O Filament já filtra o painel por tenant, mas isso vive no PHP e só cobre as
 * queries que ele monta. A RLS vive no Postgres e vale para toda query, venha
 * de onde vier: DB::table(), relação esquecida, tinker, bug.
 *
 * O tenant atual chega pela variável de sessão app.current_tenant, definida por
 * request no middleware SetPostgresTenant.
 *
 * users fica de fora de propósito: o login procura o usuário por e-mail antes de
 * existir tenant, e com RLS ninguém conseguiria entrar.
 */
return new class extends Migration
{
    /**
     * Tabela => coluna que carrega o tenant.
     * Em tenants a própria linha é o tenant, então a coluna é o id.
     */
    private const TABLES = [
        'tenants' => 'id',
        'categories' => 'tenant_id',
        'items' => 'tenant_id',
        'links' => 'tenant_id',
    ];

    public function up(): void
    {
        /*
         * current_setting(..., true) devolve NULL se a variável nunca foi
         * definida, em vez de erro. NULLIF cobre o valor vazio após o reset.
         * Comparação com NULL nunca é verdadeira: sem tenant, zero linhas.
         * Falha fechada.
         */
        $currentTenant = "NULLIF(current_setting('app.current_tenant', true), '')::bigint";

        foreach (self::TABLES as $table => $column) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            /* Sem FORCE, o dono da tabela ignoraria a policy. */
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            /*
             * USING: o que dá para ler, alterar e apagar.
             * WITH CHECK: o que dá para gravar (impede inserir ou mover
             * uma linha para outro restaurante).
             */
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                    USING ({$column} = {$currentTenant})
                    WITH CHECK ({$column} = {$currentTenant})
            ");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};

<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Dois restaurantes com dados, para testar isolamento.
 *
 * Os dados são criados pela conexão do dono (ignora RLS); o código testado roda
 * pela conexão da aplicação (presa à RLS), a mesma que atende request.
 *
 * Sem RefreshDatabase: ele roda tudo numa transação da conexão padrão, e a
 * conexão do app não enxergaria o que o dono gravou numa transação aberta.
 * Cada teste recria os dados com TRUNCATE.
 */
trait WithTwoTenants
{
    private static bool $migrated = false;

    private int $tonho;

    private int $nona;

    protected function setUpTwoTenants(): void
    {
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner']);
            self::$migrated = true;
        }

        /* CASCADE leva junto categories, items, links e users. */
        DB::connection('pgsql_owner')->statement('TRUNCATE tenants RESTART IDENTITY CASCADE');

        $this->tonho = $this->createTenant('bar-do-tonho', itemCount: 3);
        $this->nona = $this->createTenant('pizzaria-da-nona', itemCount: 2);
    }

    /** O que o SetPostgresTenant faria numa request. */
    private function actAsTenant(int $tenantId): void
    {
        DB::select("select set_config('app.current_tenant', ?, false)", [(string) $tenantId]);
    }

    private function createUser(int $tenantId): User
    {
        return User::on('pgsql_owner')->create([
            'tenant_id' => $tenantId,
            'name' => "Dono {$tenantId}",
            'email' => "dono{$tenantId}@exemplo.com",
            'password' => 'segredo123',
        ])->setConnection('pgsql');
    }

    private function firstItemId(int $tenantId): int
    {
        return DB::connection('pgsql_owner')->table('items')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');
    }

    private function firstCategoryId(int $tenantId): int
    {
        return DB::connection('pgsql_owner')->table('categories')
            ->where('tenant_id', $tenantId)
            ->value('id');
    }

    /** Itens nomeados pelo restaurante ("bar-do-tonho item 1"), para achar na tela. */
    private function createTenant(string $slug, int $itemCount): int
    {
        $owner = DB::connection('pgsql_owner');
        $now = now();

        $tenantId = $owner->table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = $owner->table('categories')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => "{$slug} categoria",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (range(1, $itemCount) as $order) {
            $owner->table('items')->insert([
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'name' => "{$slug} item {$order}",
                'price_cents' => 1000,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $tenantId;
    }
}

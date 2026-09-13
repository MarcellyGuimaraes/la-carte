<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPostgresTenant;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolamento entre restaurantes no nível do banco, não do painel.
 *
 * Os dados são criados pela conexão do dono (ignora RLS) e as verificações
 * rodam pela conexão da aplicação (presa à RLS), a mesma que atende request.
 *
 * Sem RefreshDatabase: ele roda tudo numa transação da conexão padrão, e a
 * conexão do app não enxergaria o que o dono gravou numa transação aberta.
 * Cada teste recria os dados com TRUNCATE.
 */
class RowLevelSecurityTest extends TestCase
{
    private static bool $migrated = false;

    private int $tonho;

    private int $nona;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner']);
            self::$migrated = true;
        }

        $owner = DB::connection('pgsql_owner');
        /* CASCADE leva junto categories, items, links e users. */
        $owner->statement('TRUNCATE tenants RESTART IDENTITY CASCADE');

        $this->tonho = $this->createTenant('bar-do-tonho', itemCount: 3);
        $this->nona = $this->createTenant('pizzaria-da-nona', itemCount: 2);
    }

    public function test_application_connection_is_not_privileged(): void
    {
        /*
         * Se alguém apontar DB_USERNAME para o superuser, a RLS vira enfeite
         * e todos os outros testes daqui continuariam "passando" pelo motivo
         * errado. Este teste existe para isso não acontecer em silêncio.
         */
        $role = DB::selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user',
        );

        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_owner_sees_every_tenant(): void
    {
        /* Prova de que os dados existem: zero linhas abaixo é bloqueio, não banco vazio. */
        $this->assertSame(5, DB::connection('pgsql_owner')->table('items')->count());
    }

    public function test_without_tenant_nothing_is_visible(): void
    {
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, DB::table('items')->count());
    }

    public function test_tenant_reads_only_its_own_rows(): void
    {
        $this->actAsTenant($this->tonho);

        $this->assertSame([$this->tonho], DB::table('tenants')->pluck('id')->all());
        $this->assertSame(3, DB::table('items')->count());
        $this->assertSame(1, DB::table('categories')->count());
        /* Pedir explicitamente pelo outro tenant não adianta. */
        $this->assertSame(0, DB::table('items')->where('tenant_id', $this->nona)->count());
    }

    public function test_tenant_cannot_update_or_delete_rows_of_another_tenant(): void
    {
        $this->actAsTenant($this->tonho);

        $this->assertSame(0, DB::table('items')->where('tenant_id', $this->nona)->update(['price_cents' => 1]));
        $this->assertSame(0, DB::table('items')->where('tenant_id', $this->nona)->delete());

        $untouched = DB::connection('pgsql_owner')->table('items')
            ->where('tenant_id', $this->nona)
            ->where('price_cents', '!=', 1)
            ->count();
        $this->assertSame(2, $untouched);
    }

    public function test_tenant_cannot_insert_rows_for_another_tenant(): void
    {
        $this->actAsTenant($this->tonho);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('categories')->insert([
            'tenant_id' => $this->nona,
            'name' => 'Invasão',
            'sort_order' => 1,
        ]);
    }

    public function test_tenant_cannot_move_its_rows_to_another_tenant(): void
    {
        $this->actAsTenant($this->tonho);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('categories')->update(['tenant_id' => $this->nona]);
    }

    public function test_middleware_sets_tenant_from_authenticated_user(): void
    {
        $owner = User::on('pgsql_owner')->create([
            'tenant_id' => $this->nona,
            'name' => 'Nona',
            'email' => 'nona@exemplo.com',
            'password' => 'segredo123',
        ]);

        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $owner);

        (new SetPostgresTenant)->handle($request, function () {
            /* Eloquent comum, sem filtro nenhum: quem filtra é o banco. */
            $this->assertSame(2, Item::count());
            $this->assertSame([$this->nona], Item::pluck('tenant_id')->unique()->values()->all());

            return new Response;
        });
    }

    public function test_middleware_without_user_tenant_sees_nothing(): void
    {
        $this->actAsTenant($this->tonho);

        $platformAdmin = new User(['tenant_id' => null]);
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $platformAdmin);

        /* Também prova que o middleware sobrescreve valor antigo da conexão. */
        (new SetPostgresTenant)->handle($request, function () {
            $this->assertSame(0, Item::count());

            return new Response;
        });
    }

    private function actAsTenant(int $tenantId): void
    {
        DB::select("select set_config('app.current_tenant', ?, false)", [(string) $tenantId]);
    }

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
            'name' => 'Categoria',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (range(1, $itemCount) as $order) {
            $owner->table('items')->insert([
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'name' => "Item {$order}",
                'price_cents' => 1000,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $tenantId;
    }
}

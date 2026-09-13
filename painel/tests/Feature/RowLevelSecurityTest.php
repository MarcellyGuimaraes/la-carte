<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPostgresTenant;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Isolamento entre restaurantes no nível do banco, não do painel.
 * O equivalente pelo painel está em AdminPanelTenancyTest.
 */
class RowLevelSecurityTest extends TestCase
{
    use WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
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

    public function test_item_cannot_point_to_category_of_another_tenant(): void
    {
        /*
         * A RLS sozinha não pega isto: a checagem de FK do Postgres a ignora.
         * Quem barra é a FK composta (category_id, tenant_id).
         */
        $this->actAsTenant($this->tonho);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foreign key');

        DB::table('items')->insert([
            'tenant_id' => $this->tonho,
            'category_id' => $this->firstCategoryId($this->nona),
            'name' => 'Item do Tonho na categoria da Nona',
            'price_cents' => 1,
        ]);
    }

    public function test_item_cannot_be_moved_to_category_of_another_tenant(): void
    {
        $this->actAsTenant($this->tonho);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foreign key');

        DB::table('items')
            ->where('id', $this->firstItemId($this->tonho))
            ->update(['category_id' => $this->firstCategoryId($this->nona)]);
    }

    public function test_deleting_a_tenant_still_cascades_to_its_menu(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->table('tenants')->where('id', $this->nona)->delete();

        $this->assertSame(0, $owner->table('categories')->where('tenant_id', $this->nona)->count());
        $this->assertSame(0, $owner->table('items')->where('tenant_id', $this->nona)->count());
        $this->assertSame(3, $owner->table('items')->where('tenant_id', $this->tonho)->count());
    }

    public function test_category_with_items_still_cannot_be_deleted(): void
    {
        $this->actAsTenant($this->tonho);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foreign key');

        DB::table('categories')->where('id', $this->firstCategoryId($this->tonho))->delete();
    }

    public function test_middleware_sets_tenant_from_authenticated_user(): void
    {
        $owner = $this->createUser($this->nona);

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
}

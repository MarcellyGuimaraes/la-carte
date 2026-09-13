<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Http\Middleware\SetPostgresTenant;
use App\Models\Category;
use App\Models\Item;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithPanels;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Ameaça nº 1: dono do restaurante A mexendo no restaurante B.
 *
 * Complementa AdminPanelTenancyTest (itens) e RowLevelSecurityTest (banco),
 * que também fazem parte do grupo "security".
 */
#[Group('security')]
class TenantIsolationTest extends TestCase
{
    use InteractsWithPanels, WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
    }

    /* ---- IDOR: id de outro restaurante na URL ou no componente ---- */

    public function test_owner_cannot_open_edit_page_of_another_restaurant_category(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get("/admin/bar-do-tonho/categories/{$this->firstCategoryId($this->nona)}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_mount_edit_component_for_another_restaurant_category(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditCategory::class, ['record' => $this->firstCategoryId($this->nona)]);
    }

    public function test_category_list_does_not_show_another_restaurant(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho/categories')
            ->assertOk()
            ->assertSee('bar-do-tonho categoria')
            ->assertDontSee('pizzaria-da-nona categoria');
    }

    public function test_bulk_delete_with_ids_of_another_restaurant_deletes_nothing(): void
    {
        $nonaItems = DB::connection('pgsql_owner')->table('items')->where('tenant_id', $this->nona)->pluck('id')->all();
        $this->bootAdminPanelAs($this->tonho);

        /*
         * Payload adulterado: ids que a tabela nunca ofereceu. O Filament
         * resolve os registros pela query do painel e não acha nenhum; se
         * achasse, a RLS ainda apagaria zero linhas.
         */
        rescue(fn () => Livewire::test(ListItems::class)->callTableBulkAction(DeleteBulkAction::class, $nonaItems), report: false);

        $this->assertSame(
            count($nonaItems),
            DB::connection('pgsql_owner')->table('items')->where('tenant_id', $this->nona)->count(),
        );
    }

    public function test_bulk_delete_of_categories_with_ids_of_another_restaurant_deletes_nothing(): void
    {
        /* Categoria vazia da Nona: sem itens, nada no banco impediria o delete. */
        $emptyCategory = DB::connection('pgsql_owner')->table('categories')->insertGetId([
            'tenant_id' => $this->nona, 'name' => 'vazia', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bootAdminPanelAs($this->tonho);

        rescue(fn () => Livewire::test(ListCategories::class)->callTableBulkAction(DeleteBulkAction::class, [$emptyCategory]), report: false);

        $this->assertNotNull($this->ownerRow('categories', $emptyCategory));
    }

    public function test_bulk_delete_of_own_empty_category_works(): void
    {
        /* Controle: prova que os bulk deletes acima falham pelo motivo certo. */
        $ownEmpty = DB::connection('pgsql_owner')->table('categories')->insertGetId([
            'tenant_id' => $this->tonho, 'name' => 'vazia', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(ListCategories::class)->callTableBulkAction(DeleteBulkAction::class, [$ownEmpty]);

        $this->assertNull($this->ownerRow('categories', $ownEmpty));
    }

    public function test_edit_form_of_own_item_cannot_save_into_another_restaurant_item(): void
    {
        $ownItem = $this->firstItemId($this->tonho);
        $nonaItem = $this->firstItemId($this->nona);
        $this->bootAdminPanelAs($this->tonho);

        /* Tenta trocar o registro alvo depois de montar o componente. */
        rescue(fn () => Livewire::test(EditItem::class, ['record' => $ownItem])
            ->set('record', $nonaItem)
            ->fillForm(['name' => 'invadido'])
            ->call('save'), report: false);

        $this->assertSame('pizzaria-da-nona item 1', $this->ownerRow('items', $nonaItem)->name);
    }

    /* ---- RLS: mesmo sem o scoping do Filament ---- */

    public function test_raw_queries_without_filament_scope_see_nothing_of_another_restaurant(): void
    {
        $this->actAsTenant($this->tonho);

        /* Três jeitos de "esquecer" o filtro. Quem filtra é o Postgres. */
        $this->assertSame(0, Item::withoutGlobalScopes()->where('tenant_id', $this->nona)->count());
        $this->assertSame(0, DB::table('categories')->where('tenant_id', $this->nona)->count());
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from items where tenant_id = ?', [$this->nona])->c);
        $this->assertNull(Category::withoutGlobalScopes()->find($this->firstCategoryId($this->nona)));
    }

    public function test_raw_writes_without_filament_scope_affect_zero_rows_of_another_restaurant(): void
    {
        $this->actAsTenant($this->tonho);

        $this->assertSame(0, DB::table('items')->where('tenant_id', $this->nona)->update(['price_cents' => 1]));
        $this->assertSame(0, DB::table('items')->where('tenant_id', $this->nona)->delete());
        $this->assertSame(1000, $this->ownerRow('items', $this->firstItemId($this->nona))->price_cents);
    }

    /* ---- O tenant do Postgres não vem de input do cliente ---- */

    public function test_middleware_ignores_tenant_sent_by_the_client(): void
    {
        $owner = $this->createUser($this->tonho);

        /* Tudo que um cliente controla: query, body, cookie, header. */
        $request = Request::create(
            '/admin/bar-do-tonho/items?tenant_id='.$this->nona.'&tenant='.$this->nona,
            'POST',
            ['tenant_id' => $this->nona, 'app.current_tenant' => (string) $this->nona],
            ['tenant_id' => (string) $this->nona],
            [],
            ['HTTP_X_TENANT' => (string) $this->nona, 'HTTP_X_TENANT_ID' => (string) $this->nona],
        );
        $request->setUserResolver(fn () => $owner);

        (new SetPostgresTenant)->handle($request, function () {
            $this->assertSame((string) $this->tonho, $this->currentPostgresTenant());
            $this->assertSame(0, Item::where('tenant_id', $this->nona)->count());

            return new Response;
        });
    }

    public function test_tenant_parameters_in_a_real_request_do_not_leak_another_restaurant(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->withHeaders(['X-Tenant' => (string) $this->nona, 'X-Tenant-Id' => (string) $this->nona])
            ->withCookie('tenant_id', (string) $this->nona)
            ->get("/admin/bar-do-tonho/items?tenant_id={$this->nona}&tenant={$this->nona}")
            ->assertOk()
            ->assertSee('bar-do-tonho item 1')
            ->assertDontSee('pizzaria-da-nona item 1');
    }

    public function test_sql_injection_in_the_tenant_value_is_just_a_string(): void
    {
        /*
         * Defesa do set_config com bind: mesmo que um tenant_id malicioso
         * chegasse ali, vira texto, não SQL. O cast para bigint na policy
         * falha fechado.
         */
        DB::select("select set_config('app.current_tenant', ?, false)", ["1' or '1'='1"]);

        $this->expectException(QueryException::class);

        Item::count();
    }
}

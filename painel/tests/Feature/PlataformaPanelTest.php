<?php

namespace Tests\Feature;

use App\Filament\Plataforma\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Plataforma\Resources\Tenants\Pages\ListTenants;
use App\Http\Middleware\SetPostgresTenant;
use App\Http\Middleware\UsePlatformConnection;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Painel /plataforma: a dona vê todos os restaurantes, e o bypass da RLS não
 * vaza para o /admin.
 */
#[Group('security')]
class PlataformaPanelTest extends TestCase
{
    use WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
    }

    /* ---- Qual papel do Postgres atende cada painel ---- */

    public function test_platform_middleware_runs_as_the_bypass_role(): void
    {
        (new UsePlatformConnection)->handle(Request::create('/plataforma'), function () {
            $this->assertSame('la_carte_platform', $this->currentDatabaseRole());
            /* Sem tenant definido: só enxerga os dois porque ignora a RLS. */
            $this->assertSame(2, Tenant::count());

            return new Response;
        });
    }

    public function test_admin_middleware_runs_as_the_restricted_role(): void
    {
        $request = Request::create('/admin');
        $owner = $this->createUser($this->tonho);
        $request->setUserResolver(fn () => $owner);

        (new SetPostgresTenant)->handle($request, function () {
            $this->assertSame('la_carte_app', $this->currentDatabaseRole());
            $this->assertSame(1, Tenant::count());

            return new Response;
        });
    }

    public function test_admin_middleware_refuses_to_run_on_the_bypass_connection(): void
    {
        /* Simula o erro que a trava existe para pegar: bypass chegando ao /admin. */
        DB::setDefaultConnection(UsePlatformConnection::CONNECTION);

        $this->expectException(LogicException::class);

        (new SetPostgresTenant)->handle(Request::create('/admin'), fn () => new Response);
    }

    /* ---- Quem entra em qual painel ---- */

    public function test_super_admin_sees_every_restaurant(): void
    {
        $this->actingAs($this->createSuperAdmin())
            ->get('/plataforma/tenants')
            ->assertOk()
            ->assertSee('bar-do-tonho')
            ->assertSee('pizzaria-da-nona');
    }

    public function test_owner_is_forbidden_in_platform(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get('/plataforma/tenants')
            ->assertForbidden();
    }

    public function test_super_admin_is_forbidden_in_admin(): void
    {
        $this->actingAs($this->createSuperAdmin())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_platform_request_does_not_leak_bypass_into_next_admin_request(): void
    {
        /*
         * Duas requests no mesmo processo PHP, como faria Octane: a primeira no
         * /plataforma, a segunda no /admin. A conexão tem que ter voltado.
         */
        $this->actingAs($this->createSuperAdmin())
            ->get('/plataforma/tenants')
            ->assertOk();

        $this->assertSame('pgsql', DB::getDefaultConnection());

        $this->flushSession();

        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho/items')
            ->assertOk()
            ->assertSee('bar-do-tonho item 1')
            ->assertDontSee('pizzaria-da-nona item 1');
    }

    /* ---- Onboarding e ativação ---- */

    public function test_onboarding_creates_restaurant_and_owner_who_sees_only_it(): void
    {
        $this->bootPlatform();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Cantina do Zé',
                'slug' => 'cantina-do-ze',
                'plan' => 'pro',
                'active' => true,
                'owner_name' => 'José',
                'owner_email' => 'ze@exemplo.com',
                'owner_password' => 'segredo123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->leavePlatform();

        $owner = DB::connection('pgsql_owner');
        $tenant = $owner->table('tenants')->where('slug', 'cantina-do-ze')->first();
        $user = $owner->table('users')->where('email', 'ze@exemplo.com')->first();

        $this->assertSame('pro', $tenant->plan);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertFalse($user->is_super_admin);

        $this->actingAs(User::findOrFail($user->id))
            ->get('/admin/cantina-do-ze/items')
            ->assertOk()
            ->assertDontSee('bar-do-tonho item 1');
    }

    public function test_onboarding_with_existing_email_creates_nothing(): void
    {
        $taken = $this->createUser($this->tonho)->email;
        $this->bootPlatform();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Cantina do Zé',
                'slug' => 'cantina-do-ze',
                'plan' => 'free',
                'active' => true,
                'owner_name' => 'José',
                'owner_email' => $taken,
                'owner_password' => 'segredo123',
            ])
            ->call('create')
            ->assertHasFormErrors(['owner_email']);

        $this->assertFalse(
            DB::connection('pgsql_owner')->table('tenants')->where('slug', 'cantina-do-ze')->exists(),
        );
    }

    public function test_deactivating_a_restaurant_locks_its_owner_out_of_admin(): void
    {
        $nonaOwner = $this->createUser($this->nona);
        $this->bootPlatform();

        Livewire::test(ListTenants::class)
            ->callTableAction('deactivate', Tenant::findOrFail($this->nona));

        $this->leavePlatform();

        $this->assertFalse(
            (bool) DB::connection('pgsql_owner')->table('tenants')->where('id', $this->nona)->value('active'),
        );

        $this->actingAs($nonaOwner)
            ->get('/admin/pizzaria-da-nona/items')
            ->assertNotFound();
    }

    /* ---- Regras do super-admin no banco e no model ---- */

    public function test_database_rejects_super_admin_with_a_restaurant(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_super_admin_has_no_tenant');

        $this->insertUser(tenantId: $this->tonho, isSuperAdmin: true);
    }

    public function test_database_rejects_regular_user_without_a_restaurant(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_super_admin_has_no_tenant');

        $this->insertUser(tenantId: null, isSuperAdmin: false);
    }

    public function test_super_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'x', 'is_super_admin' => true]);

        $this->assertNotTrue($user->is_super_admin);
    }

    /**
     * Livewire::test não passa pelos middlewares da rota: o que Authenticate,
     * SetUpPanel e UsePlatformConnection fariam vai à mão.
     */
    private function bootPlatform(): void
    {
        $this->actingAs($this->createSuperAdmin());
        Filament::setCurrentPanel('plataforma');
        Filament::bootCurrentPanel();
        DB::setDefaultConnection(UsePlatformConnection::CONNECTION);
    }

    /** O que o fim da request faria. */
    private function leavePlatform(): void
    {
        DB::setDefaultConnection('pgsql');
        $this->flushSession();
    }

    private function createSuperAdmin(): User
    {
        $admin = (new User)->setConnection('pgsql_owner');
        $admin->forceFill([
            'name' => 'Dona',
            'email' => 'dona@exemplo.com',
            'password' => 'segredo123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ])->save();

        return $admin->setConnection('pgsql');
    }

    private function insertUser(?int $tenantId, bool $isSuperAdmin): void
    {
        DB::connection('pgsql_owner')->table('users')->insert([
            'name' => 'x',
            'email' => 'x@exemplo.com',
            'password' => 'x',
            'tenant_id' => $tenantId,
            'is_super_admin' => $isSuperAdmin,
        ]);
    }

    private function currentDatabaseRole(): string
    {
        return DB::selectOne('select current_user as role')->role;
    }
}

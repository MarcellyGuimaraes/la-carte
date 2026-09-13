<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SetPostgresTenant;
use App\Http\Middleware\UsePlatformConnection;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * O bypass da RLS existe só para o /plataforma. Aqui: a credencial e a cadeia
 * de middleware do /admin nunca chegam nele.
 *
 * Os comportamentos em request (bypass não vaza para a próxima request, trava
 * do SetPostgresTenant) estão em PlataformaPanelTest, também do grupo.
 */
#[Group('security')]
class BypassRlsContainmentTest extends TestCase
{
    use WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
    }

    public function test_admin_connection_role_has_no_bypass_and_is_not_superuser(): void
    {
        $role = $this->role(config('database.connections.pgsql.username'));

        $this->assertFalse($role->rolbypassrls, 'o papel do /admin ignora a RLS');
        $this->assertFalse($role->rolsuper, 'o papel do /admin é superuser');
    }

    public function test_admin_connection_role_does_not_own_the_tables(): void
    {
        /* Dono de tabela ignora RLS se alguém tirar o FORCE. */
        $owned = DB::selectOne(
            "select count(*) as c from pg_tables where schemaname = 'public' and tableowner = current_user",
        )->c;

        $this->assertSame('la_carte_app', DB::selectOne('select current_user as u')->u);
        $this->assertSame(0, (int) $owned);
    }

    public function test_platform_role_bypasses_rls_but_is_not_superuser(): void
    {
        $role = $this->role(config('database.connections.pgsql_platform.username'));

        $this->assertTrue($role->rolbypassrls);
        $this->assertFalse($role->rolsuper, 'o bypass não deveria vir com superuser junto');
    }

    public function test_admin_credentials_are_not_the_privileged_ones(): void
    {
        $admin = config('database.connections.pgsql.username');

        $this->assertNotSame(config('database.connections.pgsql_platform.username'), $admin);
        $this->assertNotSame(config('database.connections.pgsql_owner.username'), $admin);
        $this->assertSame('pgsql', config('database.default'));
    }

    public function test_only_the_expected_login_roles_can_ignore_rls(): void
    {
        /* Papel novo com bypass criado "só para testar" aparece aqui. */
        $privileged = DB::table('pg_roles')
            ->where('rolcanlogin', true)
            ->where(fn ($q) => $q->where('rolbypassrls', true)->orWhere('rolsuper', true))
            ->orderBy('rolname')
            ->pluck('rolname')
            ->all();

        $expected = [
            config('database.connections.pgsql_owner.username'),
            config('database.connections.pgsql_platform.username'),
        ];
        sort($expected);

        $this->assertSame($expected, $privileged);
    }

    public function test_bypass_middleware_is_wired_only_into_the_platform_panel(): void
    {
        $admin = $this->middlewareOf('admin');
        $platform = $this->middlewareOf('plataforma');

        $this->assertNotContains(UsePlatformConnection::class, $admin);
        $this->assertContains(SetPostgresTenant::class, $admin);

        $this->assertContains(UsePlatformConnection::class, $platform);
        $this->assertNotContains(SetPostgresTenant::class, $platform);
    }

    /** @return list<string> */
    private function middlewareOf(string $panel): array
    {
        $panel = Filament::getPanel($panel);

        return array_map(
            fn (string $middleware): string => explode(':', $middleware)[0],
            [...$panel->getMiddleware(), ...$panel->getAuthMiddleware()],
        );
    }

    private function role(string $name): object
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = ?', [$name]);
        $this->assertNotNull($role, "papel [{$name}] não existe");

        return $role;
    }
}

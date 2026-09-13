<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/**
 * Preparo de painel para Livewire::test, que não passa pelos middlewares da
 * rota. Usado junto com WithTwoTenants.
 */
trait InteractsWithPanels
{
    /** O que SetPostgresTenant, IdentifyTenant e SetUpPanel fariam numa request. */
    private function bootAdminPanelAs(int $tenantId): User
    {
        $user = $this->createUser($tenantId);

        $this->actAsTenant($tenantId);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant(Tenant::findOrFail($tenantId));
        Filament::bootCurrentPanel();

        return $user;
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

    /** Lê pela conexão do dono das tabelas: enxerga o que a RLS esconderia. */
    private function ownerRow(string $table, int $id): ?object
    {
        return DB::connection('pgsql_owner')->table($table)->where('id', $id)->first();
    }

    private function currentPostgresTenant(): string
    {
        return DB::selectOne("select coalesce(current_setting('app.current_tenant', true), '') as t")->t;
    }
}

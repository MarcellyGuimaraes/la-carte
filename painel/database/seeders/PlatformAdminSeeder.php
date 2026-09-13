<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A dona do SaaS, para entrar no /plataforma em dev.
 *
 * forceFill porque is_super_admin fica fora do Fillable de propósito.
 * Em produção a super-admin é criada à mão, nunca por seed.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrNew(['email' => 'dona@exemplo.com']);

        $admin->forceFill([
            'name' => 'Dona da Plataforma',
            'password' => 'segredo123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ])->save();
    }
}

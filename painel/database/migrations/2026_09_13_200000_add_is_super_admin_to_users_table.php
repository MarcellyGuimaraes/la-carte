<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin (dona do SaaS) explícita, em vez de "tenant_id nulo".
 *
 * Com a regra implícita, qualquer usuário criado sem restaurante por engano
 * viraria dona da plataforma. Agora é preciso marcar a flag de propósito, e o
 * CHECK amarra as duas colunas: super-admin não tem restaurante, e todo
 * usuário que não é super-admin tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('tenant_id');
        });

        DB::statement('
            ALTER TABLE users ADD CONSTRAINT users_super_admin_has_no_tenant CHECK (
                (is_super_admin AND tenant_id IS NULL)
                OR (NOT is_super_admin AND tenant_id IS NOT NULL)
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_super_admin_has_no_tenant');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whitelabel: a marca do restaurante (cor, tema, logo).
 *
 * Colunas em tenants, e não uma tabela ou um jsonb à parte:
 * - a linha do tenant já está sob a RLS (policy tenant_isolation), então não
 *   nasce tabela nova que precise lembrar de ganhar policy;
 * - colunas tipadas deixam o próprio banco recusar valor inválido (CHECK),
 *   coisa que um jsonb não faz;
 * - são 4 campos. Se a marca crescer muito, separar em tabela é uma migration.
 *
 * Tudo aqui é RASCUNHO, como o resto do banco: a mesa só vê a marca depois do
 * "Publicar", que copia estes valores para o snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            /* null = cor padrão do La Carte. Sempre #rrggbb minúsculo. */
            $table->string('brand_color', 7)->nullable()->after('current_version');
            $table->string('theme', 10)->default('dark')->after('brand_color');
            /* Original enviado pelo dono. O WebP sai do job ProcessTenantLogo. */
            $table->string('logo_path')->nullable()->after('theme');
            /* URL final do WebP no CDN; é o que vai para o snapshot. */
            $table->string('logo_url')->nullable()->after('logo_path');
        });

        /*
         * O formulário já valida, mas o painel não é o único caminho até o
         * banco (tinker, seed, bug). Uma cor fora do formato iria parar no CSS
         * da mesa; aqui ela nem chega a ser gravada.
         */
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_brand_color_hex CHECK (brand_color ~ '^#[0-9a-f]{6}$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_theme_valid CHECK (theme IN ('dark', 'light'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_theme_valid');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_brand_color_hex');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'theme', 'logo_path', 'logo_url']);
        });
    }
};

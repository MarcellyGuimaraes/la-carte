<?php

use App\Support\Migrations\TenantColumns;
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
    /*
     * Idempotente (TenantColumns): em produção parte destas colunas foi criada
     * à mão no Neon. Roda igual num banco vazio e num que já tem as colunas.
     */
    public function up(): void
    {
        /* null = cor padrão do La Carte. Sempre #rrggbb minúsculo. */
        TenantColumns::ensure('brand_color', 'varchar(7)');
        TenantColumns::ensureWithDefault('theme', 'varchar(10)', "'dark'");
        /* Original enviado pelo dono. O WebP sai do job ProcessTenantImage. */
        TenantColumns::ensure('logo_path', 'varchar(255)');
        /* URL final do WebP no CDN; é o que vai para o snapshot. */
        TenantColumns::ensure('logo_url', 'varchar(255)');

        /*
         * O formulário já valida, mas o painel não é o único caminho até o
         * banco (tinker, seed, bug). Uma cor fora do formato iria parar no CSS
         * da mesa; aqui ela nem chega a ser gravada.
         */
        TenantColumns::ensureCheck('tenants_brand_color_hex', "brand_color ~ '^#[0-9a-f]{6}$'");
        TenantColumns::ensureCheck('tenants_theme_valid', "theme IN ('dark', 'light')");
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

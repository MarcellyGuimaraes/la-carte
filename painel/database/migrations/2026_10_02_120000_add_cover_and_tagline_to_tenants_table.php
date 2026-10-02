<?php

use App\Support\Migrations\TenantColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whitelabel, segunda parte: capa e slogan do restaurante.
 *
 * Migration nova em vez de editar a de branding: aquela pode já ter rodado em
 * produção, e editar uma migration aplicada não muda nada no banco que já a
 * tem, só deixa o histórico mentindo.
 *
 * Mesmo raciocínio da anterior: colunas em tenants, cuja linha já está sob a
 * policy tenant_isolation. Nada aqui pede policy ou GRANT novo: coluna nova
 * herda os privilégios da tabela.
 *
 * Só ADD de colunas nullable: seguro com a tabela cheia, e o código antigo
 * convive com elas (rollback de deploy sem rollback de migration).
 */
return new class extends Migration
{
    /* Idempotente (TenantColumns): colunas podem já existir, criadas à mão no Neon. */
    public function up(): void
    {
        /* Original enviado; o WebP sai do job ProcessTenantImage. */
        TenantColumns::ensure('cover_path', 'varchar(255)');
        /* URL final do WebP no CDN; é o que vai para o snapshot. */
        TenantColumns::ensure('cover_url', 'varchar(255)');
        /* O próprio varchar(140) é o limite no banco. */
        TenantColumns::ensure('tagline', 'varchar(140)');

        /*
         * Slogan vazio é null, nunca ''. Sem isso, '' e null publicariam
         * snapshots diferentes para o mesmo cardápio (versão nova à toa).
         */
        TenantColumns::ensureCheck('tenants_tagline_not_blank', "tagline IS NULL OR btrim(tagline) <> ''");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_tagline_not_blank');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'cover_url', 'tagline']);
        });
    }
};

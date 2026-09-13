<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última versão de snapshot reservada para o restaurante.
 *
 * 0 = nunca publicou. O job de publicação incrementa ANTES de gravar o
 * arquivo: se falhar no meio, o número fica queimado e nunca é reaproveitado.
 * Buraco na numeração é inofensivo; reescrever um v{n}.json imutável que algum
 * celular já guardou não é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('current_version')->default(0)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('current_version');
        });
    }
};

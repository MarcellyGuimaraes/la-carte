<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurantes. É a raiz de tudo: toda outra tabela carrega um tenant_id
 * apontando para cá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            /* Vai na URL do QR code, por isso precisa ser único. */
            $table->string('slug')->unique();
            /* Tier e limites. Cobrança é manual nos primeiros meses. */
            $table->string('plan')->default('free');
            /* Desliga um cliente sem apagar o cardápio dele. */
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

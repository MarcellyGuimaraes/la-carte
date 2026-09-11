<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os produtos do cardápio.
 *
 * tenant_id é redundante: daria para chegar ao restaurante pela categoria.
 * A redundância é proposital, porque a Row Level Security do Postgres precisa
 * da coluna na própria tabela, não num join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /* Bloqueia apagar uma categoria que ainda tem itens. */
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            /* Centavos inteiros: float erra em dinheiro. Mesmo contrato da PWA. */
            $table->unsignedInteger('price_cents');
            /* O WebP no CDN (passo 8 do roteiro). */
            $table->string('image_url')->nullable();
            $table->boolean('featured')->default(false);
            /* Falso = existe no cardápio, mas acabou hoje. */
            $table->boolean('available')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

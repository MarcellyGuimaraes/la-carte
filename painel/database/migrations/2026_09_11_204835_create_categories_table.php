<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bebidas, petiscos, pratos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            /* A ordem que o dono escolhe, não a ordem de criação. */
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            /* Exatamente como o snapshot do cardápio vai ler. */
            $table->index(['tenant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

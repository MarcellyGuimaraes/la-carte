<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links tipo "link na bio" do restaurante: Instagram, WhatsApp, iFood.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url');
            $table->integer('sort_order')->default(0);
            /* Esconde um link sem perder o endereço. */
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};

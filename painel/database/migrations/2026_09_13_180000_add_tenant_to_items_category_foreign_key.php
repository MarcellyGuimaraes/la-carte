<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item e categoria precisam ser do mesmo restaurante, garantido pelo banco.
 *
 * A FK simples (category_id -> categories.id) aceitava um item do Tonho numa
 * categoria da Nona: a verificação de chave estrangeira do Postgres ignora a
 * RLS. Com a FK composta, o par (category_id, tenant_id) do item precisa existir
 * igual em categories, então a categoria tem que ser do mesmo tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Uma FK só pode apontar para colunas com unicidade garantida. id já é
         * único sozinho, então esta constraint não restringe nada a mais: existe
         * só para servir de alvo da FK composta.
         */
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['category_id']);

            /*
             * noAction em vez de restrict: ambos bloqueiam apagar categoria com
             * itens, mas NO ACTION só confere no fim do comando. Isso deixa o
             * cascade de "apagar restaurante" remover itens e categorias juntos
             * sem tropeçar na ordem em que o Postgres apaga cada tabela.
             */
            $table->foreign(['category_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('categories')
                ->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['category_id', 'tenant_id']);
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['id', 'tenant_id']);
        });
    }
};

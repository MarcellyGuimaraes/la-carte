<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa a foto que o dono enviou da foto que o cliente vê.
 *
 * image_path  caminho do original no disco, preenchido pelo formulário.
 * image_url   URL absoluta do WebP de 800px, preenchida pelo job de imagem.
 *
 * Numa coluna só, o job sobrescreveria o caminho que o FileUpload usa para
 * mostrar a foto na edição, e a PWA receberia um caminho em vez de URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('price_cents');
        });

        /*
         * Até aqui image_url guardava o caminho do upload. Move para a coluna
         * certa; o WebP nasce quando a foto for reenviada.
         */
        DB::table('items')
            ->whereNotNull('image_url')
            ->where('image_url', 'not like', 'http%')
            ->update(['image_path' => DB::raw('image_url'), 'image_url' => null]);
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};

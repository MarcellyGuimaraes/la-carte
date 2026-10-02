<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whitelabel, terceira parte: cor secundária e contatos do restaurante.
 *
 * brand_color continua sendo a cor PRIMÁRIA: renomear quebraria o contrato do
 * snapshot (branding.brand_color) que já está em cache nos celulares.
 *
 * Contatos como colunas tipadas, não na tabela links: são três campos de
 * formato conhecido, que o banco consegue validar (CHECK). A tabela links fica
 * para a futura página "link na bio", que é lista livre.
 *
 * Mesmas garantias das anteriores: só ADD de colunas nullable, sob a policy
 * tenant_isolation que já cobre a linha, sem GRANT novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            /* null = selo e preço usam a cor primária. */
            $table->string('secondary_color', 7)->nullable()->after('brand_color');
            /* Só dígitos, com DDI: 5511999998888. Vira https://wa.me/<isto>. */
            $table->string('whatsapp', 13)->nullable()->after('tagline');
            /* Usuário sem @, minúsculo. Vira https://instagram.com/<isto>. */
            $table->string('instagram', 30)->nullable()->after('whatsapp');
            $table->string('address', 200)->nullable()->after('instagram');
        });

        /*
         * WhatsApp e Instagram viram URL na mesa: formato errado não pode nem
         * chegar a ser gravado, venha de onde vier (painel, tinker, seed).
         */
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_secondary_color_hex CHECK (secondary_color ~ '^#[0-9a-f]{6}$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_whatsapp_digits CHECK (whatsapp ~ '^[0-9]{12,13}$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_instagram_handle CHECK (instagram ~ '^[a-z0-9._]{1,30}$')");
        /* Como o slogan: vazio é null, nunca '' (senão snapshots diferentes para o mesmo conteúdo). */
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_address_not_blank CHECK (address IS NULL OR btrim(address) <> '')");
    }

    public function down(): void
    {
        foreach (['tenants_address_not_blank', 'tenants_instagram_handle', 'tenants_whatsapp_digits', 'tenants_secondary_color_hex'] as $constraint) {
            DB::statement("ALTER TABLE tenants DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['secondary_color', 'whatsapp', 'instagram', 'address']);
        });
    }
};

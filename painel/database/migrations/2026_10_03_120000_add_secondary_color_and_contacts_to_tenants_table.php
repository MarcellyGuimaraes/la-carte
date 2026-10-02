<?php

use App\Support\Migrations\TenantColumns;
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
    /* Idempotente (TenantColumns): colunas podem já existir, criadas à mão no Neon. */
    public function up(): void
    {
        /* null = selo e preço usam a cor primária. */
        TenantColumns::ensure('secondary_color', 'varchar(7)');
        /* Só dígitos, com DDI: 5511999998888. Vira https://wa.me/<isto>. */
        TenantColumns::ensure('whatsapp', 'varchar(13)');
        /* Usuário sem @, minúsculo. Vira https://instagram.com/<isto>. */
        TenantColumns::ensure('instagram', 'varchar(30)');
        TenantColumns::ensure('address', 'varchar(200)');

        /*
         * WhatsApp e Instagram viram URL na mesa: formato errado não pode nem
         * chegar a ser gravado, venha de onde vier (painel, tinker, seed).
         */
        TenantColumns::ensureCheck('tenants_secondary_color_hex', "secondary_color ~ '^#[0-9a-f]{6}$'");
        TenantColumns::ensureCheck('tenants_whatsapp_digits', "whatsapp ~ '^[0-9]{12,13}$'");
        TenantColumns::ensureCheck('tenants_instagram_handle', "instagram ~ '^[a-z0-9._]{1,30}$'");
        /* Como o slogan: vazio é null, nunca '' (senão snapshots diferentes para o mesmo conteúdo). */
        TenantColumns::ensureCheck('tenants_address_not_blank', "address IS NULL OR btrim(address) <> ''");
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

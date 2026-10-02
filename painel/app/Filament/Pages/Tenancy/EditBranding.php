<?php

namespace App\Filament\Pages\Tenancy;

use App\Filament\Schemas\BrandingFields;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Whitelabel: o dono escolhe tema, cor, slogan, logo e capa do restaurante.
 * Os campos são os mesmos do /plataforma (BrandingFields).
 *
 * É a página de "perfil do tenant" do Filament (menu do restaurante, no topo).
 * Grava na linha do tenant, que está sob a RLS: o dono só alcança a dele.
 *
 * Só os campos deste formulário são gravados. Nome, slug, plano e ativo ficam
 * com a plataforma; nada aqui deixa o dono mexer neles.
 */
class EditBranding extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Marca do restaurante';
    }

    /*
     * O padrão do Filament consulta uma policy de Tenant que não existe (e,
     * sem policy, libera). Explícito é melhor: só o dono do restaurante.
     */
    public static function canView(Model $tenant): bool
    {
        return auth()->user()?->tenant_id === $tenant->getKey();
    }

    /* Diz o que acontece de fato: salvar já publica a marca (Tenant::booted). */
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Marca salva e enviada para o cardápio';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Aparência do cardápio')
                    ->description('Ao salvar, vai direto para o cardápio das mesas em alguns segundos (logo e capa novos, assim que terminarem de processar). Itens e categorias continuam esperando o "Publicar cardápio".')
                    ->schema([
                        ...BrandingFields::basic(),
                        ...BrandingFields::images(),
                    ]),
                Section::make('Contatos no cardápio')
                    ->description('Aparecem como links no rodapé do cardápio. Deixe vazio o que não quiser mostrar.')
                    ->schema(BrandingFields::contacts()),
            ]);
    }
}

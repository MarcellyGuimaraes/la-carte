<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\Theme;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Whitelabel: o dono escolhe cor, tema e logo do restaurante.
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Aparência do cardápio')
                    ->description('Vale para as mesas depois de "Publicar cardápio". O painel muda na hora.')
                    ->schema([
                        ToggleButtons::make('theme')
                            ->label('Tema')
                            ->options(Theme::class)
                            ->inline()
                            ->required(),
                        /*
                         * Vazio = cor padrão do La Carte. Minúsculo para bater
                         * com o CHECK do banco e o snapshot não mudar só por
                         * caixa da letra (o que geraria versão nova à toa).
                         */
                        ColorPicker::make('brand_color')
                            ->label('Cor da marca')
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : strtolower($state))
                            ->helperText('Usada em destaques e botões. Deixe vazio para a cor padrão.'),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            /*
                             * Mesmas travas da foto do item (ver ItemForm): sem
                             * SVG, conteúdo conferido de verdade e caminho que o
                             * cliente não consegue trocar no estado do Livewire.
                             */
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rule('dimensions:min_width=1,min_height=1')
                            ->preventFilePathTampering()
                            ->disk('public')
                            ->directory(fn (): string => 'logos/'.Filament::getTenant()->getKey())
                            ->maxSize(2048)
                            ->helperText('PNG com fundo transparente fica melhor nos dois temas.'),
                    ]),
            ]);
    }
}

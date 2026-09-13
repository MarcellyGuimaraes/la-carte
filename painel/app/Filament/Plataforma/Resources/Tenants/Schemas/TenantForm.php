<?php

namespace App\Filament\Plataforma\Resources\Tenants\Schemas;

use App\Enums\Plan;
use App\Models\Tenant;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Onboarding de restaurante: o restaurante e o usuário-dono num formulário só.
 *
 * Os campos do dono existem só na criação; quem grava as duas coisas juntas,
 * numa transação, é a página CreateTenant.
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Restaurante')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            /* Sugere o slug a partir do nome, só ao criar e só se estiver vazio. */
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label('Endereço (slug)')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->unique(Tenant::class, 'slug', ignoreRecord: true)
                            /*
                             * Travado depois de criado: o slug está impresso no QR
                             * code das mesas. Campo desabilitado não é gravado.
                             */
                            ->disabledOn('edit')
                            ->helperText('Vai na URL do QR code. Não muda depois de criado.'),
                        Select::make('plan')
                            ->label('Plano')
                            ->options(Plan::class)
                            ->default(Plan::Free)
                            ->required(),
                        Toggle::make('active')
                            ->label('Ativo')
                            ->default(true)
                            ->helperText('Desativado, o dono não entra no painel.'),
                    ]),
                Section::make('Dono')
                    ->description('A pessoa que vai gerenciar o cardápio. Repasse a senha inicial para ela.')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email'),
                        TextInput::make('owner_password')
                            ->label('Senha inicial')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8),
                    ]),
            ]);
    }
}

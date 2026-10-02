<?php

namespace App\Filament\Schemas;

use App\Enums\TenantImage;
use App\Enums\Theme;
use App\Models\Tenant;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;

/**
 * Os campos de marca do restaurante, numa fonte só para os dois painéis:
 * "Marca do restaurante" no /admin (EditBranding) e a seção "Marca" do
 * /plataforma (TenantForm).
 *
 * Nos dois painéis o registro do formulário é o próprio restaurante (o
 * EditTenantProfile do Filament usa o tenant como model), então o id para a
 * pasta de upload sai do $record, sem cada painel precisar informar.
 */
class BrandingFields
{
    /** @return list<Component> tema, cores e slogan: dá para preencher antes de o restaurante existir. */
    public static function basic(): array
    {
        return [
            ToggleButtons::make('theme')
                ->label('Tema')
                ->options(Theme::class)
                ->default(Theme::Dark)
                ->inline()
                ->required(),
            /* brand_color é a primária: o nome da coluna ficou da primeira versão (contrato do snapshot). */
            self::color('brand_color')
                ->label('Cor primária')
                ->helperText('A cor da casa: tinge o fundo e pinta as categorias e a barra do topo. Deixe vazio para a cor padrão.'),
            self::color('secondary_color')
                ->label('Cor secundária')
                ->helperText('A cor de chamada: selo "destaque" e preços. Vazio = usa a primária. Em textos, as cores podem ficar um pouco mais claras ou escuras para garantir a leitura.'),
            /* Vazio vira null: o banco recusa '' (CHECK tenants_tagline_not_blank). */
            TextInput::make('tagline')
                ->label('Slogan')
                ->maxLength(140)
                ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : trim($state))
                ->helperText('Uma frase curta abaixo do nome, no topo do cardápio.'),
        ];
    }

    /**
     * Contatos no rodapé do cardápio. O dono digita do jeito que tem em mãos
     * (com máscara, com @, colando o link do perfil); aqui vira o formato que
     * o banco aceita (CHECKs da migration de contatos) e que a mesa transforma
     * em link. A validação roda sobre o valor JÁ normalizado.
     *
     * @return list<Component>
     */
    public static function contacts(): array
    {
        return [
            TextInput::make('whatsapp')
                ->label('WhatsApp')
                ->tel()
                ->placeholder('(11) 99999-8888')
                ->rule(self::normalizedRule(self::normalizeWhatsapp(...), 'Informe o número com DDD, ex.: (11) 99999-8888.'))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeWhatsapp($state))
                ->helperText('Com DDD. O código do Brasil (55) entra sozinho.'),
            TextInput::make('instagram')
                ->label('Instagram')
                ->prefix('@')
                ->rule(self::normalizedRule(self::normalizeInstagram(...), 'Use só o nome de usuário, ex.: bardotonho.'))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeInstagram($state))
                ->helperText('O usuário ou o link do perfil.'),
            /* Vazio vira null: o banco recusa '' (CHECK tenants_address_not_blank). */
            TextInput::make('address')
                ->label('Endereço')
                ->maxLength(200)
                ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : trim($state))
                ->helperText('Abre no mapa quando o cliente toca.'),
        ];
    }

    /**
     * Logo e capa. Só na edição: o upload vai para {logos|covers}/{tenant_id},
     * e antes de criar o restaurante não há id.
     *
     * @return list<Component>
     */
    public static function images(): array
    {
        return [
            self::upload(TenantImage::Logo)
                ->label('Logo')
                ->helperText('PNG com fundo transparente fica melhor nos dois temas.'),
            self::upload(TenantImage::Cover)
                ->label('Capa')
                ->helperText('Foto larga, no topo do cardápio. Aparece cortada numa faixa: deixe o principal no centro.'),
        ];
    }

    /**
     * Só dígitos, com DDI. 10 ou 11 dígitos = número brasileiro sem o 55.
     * null = vazio OU inválido (quem distingue é normalizedRule).
     */
    public static function normalizeWhatsapp(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55'.$digits;
        }

        return preg_match('/^[0-9]{12,13}$/', $digits) ? $digits : null;
    }

    /** Aceita "usuario", "@usuario" e o link do perfil. Mesmo formato do CHECK. */
    public static function normalizeInstagram(?string $value): ?string
    {
        $handle = strtolower(trim((string) $value));
        $handle = preg_replace('#^(https?://)?(www\.)?instagram\.com/#', '', $handle);
        $handle = ltrim(rtrim($handle, '/'), '@');

        return preg_match('/^[a-z0-9._]{1,30}$/', $handle) ? $handle : null;
    }

    /**
     * Campo vazio passa (é opcional); preenchido precisa normalizar para algo
     * válido. Embrulhada em fn (): o Filament avalia a closure de ->rule() para
     * obter a regra, e a regra em si é a closure de dentro.
     */
    private static function normalizedRule(Closure $normalize, string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($normalize, $message): void {
            if (filled($value) && $normalize($value) === null) {
                $fail($message);
            }
        };
    }

    /**
     * Vazio = cor padrão. Minúsculo para bater com o CHECK do banco e o
     * snapshot não mudar só por caixa da letra (o que geraria versão à toa).
     */
    private static function color(string $name): ColorPicker
    {
        return ColorPicker::make($name)
            ->regex('/^#[0-9a-fA-F]{6}$/')
            ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : strtolower($state));
    }

    private static function upload(TenantImage $image): FileUpload
    {
        return FileUpload::make($image->pathColumn())
            ->visibleOn('edit')
            ->image()
            /*
             * Mesmas travas da foto do item (ver ItemForm): sem SVG, conteúdo
             * conferido de verdade e caminho que o cliente não consegue trocar
             * no estado do Livewire.
             */
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->rule('dimensions:min_width=1,min_height=1')
            ->preventFilePathTampering()
            ->disk('public')
            ->directory(fn (Tenant $record): string => $image->directory().'/'.$record->getKey())
            ->maxSize($image === TenantImage::Cover ? 4096 : 2048);
    }
}

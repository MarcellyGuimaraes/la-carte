<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Formulário de item do cardápio.
 *
 * Rótulos em português porque quem preenche é o dono do bar. Os nomes de
 * campo continuam em inglês, iguais às colunas e ao contrato JSON.
 *
 * Sem campo de restaurante: a tenancy do Filament preenche o tenant_id.
 */
class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->label('Categoria')
                    ->relationship('category', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3)
                    ->columnSpanFull(),
                /*
                 * O banco guarda centavos, mas ninguém digita 1200 para R$ 12,00.
                 * A conversão vive só aqui, na borda: o model continua inteiro.
                 */
                TextInput::make('price_cents')
                    ->label('Preço')
                    ->prefix('R$')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    /* Validado em reais, antes da conversão: 100000.00 não passa. */
                    ->maxValue(Item::MAX_PRICE_CENTS / 100)
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : number_format($state / 100, 2, '.', ''),
                    )
                    ->dehydrateStateUsing(
                        fn (?string $state): int => (int) round(
                            ((float) str_replace(',', '.', (string) $state)) * 100,
                        ),
                    ),
                /*
                 * Grava o original em image_path. O WebP e a image_url saem do
                 * job ProcessItemImage, na fila.
                 */
                FileUpload::make('image_path')
                    ->label('Foto')
                    ->image()
                    /*
                     * Só o que o GD converte. image() sozinho aceita image/*,
                     * que inclui SVG: um SVG pode carregar <script> e o original
                     * é servido em /storage, na mesma origem dos painéis.
                     */
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    /*
                     * O tipo acima é deduzido pela extensão quando o conteúdo é
                     * ambíguo: texto renomeado para .jpg passaria. dimensions
                     * abre o arquivo de verdade (getimagesize) e só aceita
                     * imagem legível.
                     */
                    ->rule('dimensions:min_width=1,min_height=1')
                    /*
                     * Desligado por padrão no Filament: sem isto, o cliente
                     * troca o caminho no estado do Livewire e o item passa a
                     * apontar para a foto de outro restaurante (ou "../../.env").
                     */
                    ->preventFilePathTampering()
                    ->disk('public')
                    /*
                     * Uma pasta por restaurante: dá para achar, medir e apagar
                     * as fotos de um cliente sem varrer as dos outros.
                     */
                    ->directory(fn (): string => 'itens/'.Filament::getTenant()->getKey())
                    ->maxSize(4096)
                    ->helperText('Some com a foto se o prato não tiver uma boa. Foto ruim vende menos que nenhuma.'),
                Toggle::make('featured')
                    ->label('Em destaque'),
                Toggle::make('available')
                    ->label('Disponível')
                    ->default(true)
                    ->helperText('Desligue quando acabar no dia, sem precisar apagar o item.'),
                TextInput::make('sort_order')
                    ->label('Ordem')
                    ->required()
                    ->integer()
                    ->minValue(-Item::MAX_SORT_ORDER)
                    ->maxValue(Item::MAX_SORT_ORDER)
                    ->default(0)
                    ->helperText('Menor aparece primeiro dentro da categoria.'),
            ]);
    }
}

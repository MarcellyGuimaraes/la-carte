<?php

namespace App\Models;

use App\Jobs\ProcessItemImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um produto do cardápio.
 *
 * O preço vive em centavos inteiros, igual ao contrato que a PWA já consome.
 * Nenhuma conversão para reais acontece aqui: isso é trabalho de exibição.
 */
#[Fillable([
    'tenant_id',
    'category_id',
    'name',
    'description',
    'price_cents',
    'image_path',
    'image_url',
    'featured',
    'available',
    'sort_order',
])]
class Item extends Model
{
    /*
     * No model, não no formulário do Filament: qualquer caminho que troque a
     * foto (painel, tinker, seed) gera o WebP igual.
     */
    protected static function booted(): void
    {
        /* Foto nova: a URL antiga apontaria para a foto errada até o job rodar. */
        static::saving(function (Item $item): void {
            if ($item->isDirty('image_path')) {
                $item->image_url = null;
            }
        });

        /*
         * isDirty ainda vale no "saved" (o original só sincroniza depois), e
         * cobre criação e edição. afterCommit: se houver transação, o worker
         * não pode pegar o job antes de o item existir no banco.
         */
        static::saved(function (Item $item): void {
            if ($item->isDirty('image_path') && $item->image_path !== null) {
                ProcessItemImage::dispatch($item->tenant_id, $item->id, $item->image_path)
                    ->afterCommit();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'featured' => 'boolean',
            'available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

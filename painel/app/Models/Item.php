<?php

namespace App\Models;

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
    'image_url',
    'featured',
    'available',
    'sort_order',
])]
class Item extends Model
{
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

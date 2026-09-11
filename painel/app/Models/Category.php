<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bebidas, petiscos, pratos.
 */
#[Fillable(['tenant_id', 'name', 'sort_order'])]
class Category extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}

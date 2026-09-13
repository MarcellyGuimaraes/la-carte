<?php

namespace App\Models;

use App\Enums\Plan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um restaurante. Raiz da árvore de dados: tudo mais pertence a um tenant.
 */
#[Fillable(['name', 'slug', 'plan', 'active'])]
class Tenant extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'plan' => Plan::class,
        ];
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Itens direto pelo tenant, sem passar pelas categorias.
     * É o que serializa o snapshot sem N+1.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** @return HasMany<Link, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

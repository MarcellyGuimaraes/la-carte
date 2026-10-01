<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\Theme;
use App\Jobs\ProcessTenantLogo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um restaurante. Raiz da árvore de dados: tudo mais pertence a um tenant.
 *
 * Também guarda a marca (whitelabel): brand_color, theme e logo. Como o resto
 * do banco, é rascunho até o "Publicar".
 */
#[Fillable(['name', 'slug', 'plan', 'active', 'brand_color', 'theme', 'logo_path'])]
class Tenant extends Model
{
    /*
     * Mesmo esquema do Item: no model, para qualquer caminho que troque o
     * logo (painel, tinker, seed) gerar o WebP igual.
     */
    protected static function booted(): void
    {
        /* Logo novo ou removido: a URL antiga apontaria para o logo errado. */
        static::saving(function (Tenant $tenant): void {
            if ($tenant->isDirty('logo_path')) {
                $tenant->logo_url = null;
            }
        });

        static::saved(function (Tenant $tenant): void {
            if ($tenant->isDirty('logo_path') && $tenant->logo_path !== null) {
                ProcessTenantLogo::dispatch($tenant->id, $tenant->logo_path)->afterCommit();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'plan' => Plan::class,
            'theme' => Theme::class,
            'current_version' => 'integer',
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

<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\TenantImage;
use App\Enums\Theme;
use App\Jobs\ProcessTenantImage;
use App\Jobs\PublishMenu;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um restaurante. Raiz da árvore de dados: tudo mais pertence a um tenant.
 *
 * Também guarda a marca (whitelabel): tema, cor primária (brand_color) e
 * secundária, slogan, logo, capa e os contatos do rodapé.
 * Diferente do cardápio, a marca não espera o "Publicar": salvar já a leva
 * para a mesa (ver booted()).
 */
#[Fillable(['name', 'slug', 'plan', 'active', 'brand_color', 'secondary_color', 'theme', 'tagline', 'logo_path', 'cover_path', 'whatsapp', 'instagram', 'address'])]
class Tenant extends Model
{
    /*
     * Mesmo esquema do Item: no model, para qualquer caminho que troque logo
     * ou capa (/admin, /plataforma, tinker, seed) gerar o WebP igual.
     */
    protected static function booted(): void
    {
        /* Imagem nova ou removida: a URL antiga apontaria para a imagem errada. */
        static::saving(function (Tenant $tenant): void {
            foreach (TenantImage::cases() as $image) {
                if ($tenant->isDirty($image->pathColumn())) {
                    $tenant->{$image->urlColumn()} = null;
                }
            }
        });

        /* isDirty, não wasChanged: no saved o original ainda não foi sincronizado, e vale também na criação. */
        static::saved(function (Tenant $tenant): void {
            $uploading = false;

            foreach (TenantImage::cases() as $image) {
                $path = $tenant->{$image->pathColumn()};

                if ($tenant->isDirty($image->pathColumn()) && $path !== null) {
                    ProcessTenantImage::dispatch($tenant->id, $image, $path)->afterCommit();
                    $uploading = true;
                }
            }

            /*
             * Salvar a marca já a leva para a mesa (só a marca, ver PublishMenu).
             * Com imagem nova, quem publica é o ProcessTenantImage quando o WebP
             * fica pronto: publicar agora mandaria a URL vazia. A cor salva junto
             * vai nessa mesma versão, porque o job lê o banco.
             * Restaurante recém-criado não tem versão no ar: nada a fazer.
             */
            if (! $uploading && ! $tenant->wasRecentlyCreated && $tenant->isDirty(self::PUBLISHED_BRANDING)) {
                dispatch(PublishMenu::branding($tenant->id))->afterCommit();
            }
        });
    }

    /** Colunas que mudam branding e contacts do snapshot (as URLs mudam pelos *_path). */
    private const PUBLISHED_BRANDING = [
        'theme', 'brand_color', 'secondary_color', 'tagline', 'logo_path', 'cover_path',
        'whatsapp', 'instagram', 'address',
    ];

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

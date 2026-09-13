<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['tenant_id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * O restaurante a que este usuário pertence.
     * Nulo significa administrador da plataforma, que enxerga todos.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * O /admin é do dono de restaurante. Sem isto o Filament bloqueia todo
     * mundo fora do ambiente local. O /plataforma entra no passo 4.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->tenant_id !== null;
    }

    /**
     * Restaurantes que este usuário pode abrir no painel: só o dele.
     * Hoje é um restaurante por usuário (coluna, não tabela pivô).
     */
    public function getTenants(Panel $panel): Collection
    {
        return collect([$this->tenant])->filter();
    }

    /**
     * Barreira do Filament contra trocar o slug na URL. A RLS em tenants já
     * esconderia o outro restaurante; esta checagem dá o erro antes.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->tenant_id !== null && $tenant->getKey() === $this->tenant_id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

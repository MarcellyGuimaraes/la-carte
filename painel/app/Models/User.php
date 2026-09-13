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

/*
 * is_super_admin fica fora do Fillable de propósito: nenhum formulário
 * consegue promover alguém a dona da plataforma por mass assignment.
 */
#[Fillable(['tenant_id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * O restaurante a que este usuário pertence.
     * Nulo só para a super-admin (garantido por CHECK no banco).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Quem entra em qual painel. Sem isto o Filament bloqueia todo mundo fora
     * do ambiente local.
     *
     * Roda no Authenticate, antes do SetPostgresTenant: não pode consultar
     * tabela com RLS aqui (o restaurante viria vazio). Por isso a checagem de
     * restaurante ativo fica em canAccessTenant.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        /*
         * === true de propósito: model recém-criado não traz o default do banco
         * (vem null). Na dúvida, a plataforma fica fechada.
         */
        return match ($panel->getId()) {
            'admin' => $this->is_super_admin !== true && $this->tenant_id !== null,
            'plataforma' => $this->is_super_admin === true,
            default => false,
        };
    }

    /**
     * Restaurantes que este usuário pode abrir no /admin: só o dele, e só se
     * estiver ativo. Hoje é um restaurante por usuário (coluna, não pivô).
     */
    public function getTenants(Panel $panel): Collection
    {
        return collect([$this->tenant])->filter(fn (?Tenant $tenant) => $tenant?->active);
    }

    /**
     * Barreira do Filament contra trocar o slug na URL. A RLS em tenants já
     * esconderia o outro restaurante; esta checagem dá o erro antes.
     * Restaurante desativado pela plataforma também fecha o painel do dono.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->tenant_id !== null
            && $tenant->getKey() === $this->tenant_id
            && $tenant->active;
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
            'is_super_admin' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

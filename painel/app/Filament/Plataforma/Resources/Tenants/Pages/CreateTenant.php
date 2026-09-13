<?php

namespace App\Filament\Plataforma\Resources\Tenants\Pages;

use App\Filament\Plataforma\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding: cria restaurante e dono juntos, ou nenhum dos dois.
 */
class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /*
         * Transação na conexão padrão, que aqui é a da plataforma: se o dono
         * falhar (e-mail repetido que escapou da validação, por exemplo), o
         * restaurante também não fica gravado pela metade.
         */
        return DB::transaction(function () use ($data): Tenant {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'plan' => $data['plan'],
                'active' => $data['active'],
            ]);

            $tenant->users()->create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ]);

            return $tenant;
        });
    }
}

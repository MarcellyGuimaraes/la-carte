<?php

namespace App\Filament\Plataforma\Resources\Tenants\Pages;

use App\Filament\Plataforma\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Sem ação de apagar: cliente que sai é desativado (ver TenantsTable).
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;
}

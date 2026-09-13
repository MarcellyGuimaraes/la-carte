<?php

namespace App\Filament\Plataforma\Resources\Tenants;

use App\Filament\Plataforma\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Plataforma\Resources\Tenants\Pages\EditTenant;
use App\Filament\Plataforma\Resources\Tenants\Pages\ListTenants;
use App\Filament\Plataforma\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Plataforma\Resources\Tenants\Tables\TenantsTable;
use App\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Restaurantes, vistos pela dona do SaaS: todos, sem scoping.
 *
 * Quem garante que isto enxerga todos é a conexão com BYPASSRLS do painel
 * /plataforma, não este arquivo. Registrado no /admin, veria só o restaurante
 * do dono (e nem é descoberto por ele: vive fora de app/Filament/Resources).
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'Restaurante';

    protected static ?string $pluralModelLabel = 'Restaurantes';

    protected static ?string $navigationLabel = 'Restaurantes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}

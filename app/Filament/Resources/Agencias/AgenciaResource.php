<?php

namespace App\Filament\Resources\Agencias;

use App\Filament\Resources\Agencias\Pages\CreateAgencia;
use App\Filament\Resources\Agencias\Pages\EditAgencia;
use App\Filament\Resources\Agencias\Pages\ListAgencias;
use App\Filament\Resources\Agencias\Schemas\AgenciaForm;
use App\Filament\Resources\Agencias\Tables\AgenciasTable;
use App\Models\Agencia;
use App\Support\AgenciaActual;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Agencias de la plataforma.
 *
 * El superadmin las ve todas, las crea (con su primer admin) y las activa o
 * desactiva. El admin de una agencia ve solo la suya, como "Mi agencia", para
 * editar su marca y sus apps de API; el global scope de Agencia se encarga de
 * que no llegue a ninguna otra.
 */
class AgenciaResource extends Resource
{
    protected static ?string $model = Agencia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Agencia';

    protected static ?string $pluralModelLabel = 'Agencias';

    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return AgenciaActual::esSuperadmin() ? 'Agencias' : 'Mi agencia';
    }

    public static function form(Schema $schema): Schema
    {
        return AgenciaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgenciasTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return AgenciaActual::esSuperadmin();
    }

    /** Una agencia con clientes o usuarios se desactiva, no se borra (la base de datos tampoco lo deja). */
    public static function canDelete(Model $record): bool
    {
        return AgenciaActual::esSuperadmin()
            && ! $record->clientes()->withoutGlobalScopes()->exists()
            && ! $record->usuarios()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAgencias::route('/'),
            'create' => CreateAgencia::route('/create'),
            'edit'   => EditAgencia::route('/{record}/edit'),
        ];
    }
}

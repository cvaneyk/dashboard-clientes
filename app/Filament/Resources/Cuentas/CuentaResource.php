<?php

namespace App\Filament\Resources\Cuentas;

use App\Filament\Resources\Cuentas\Pages\CreateCuenta;
use App\Filament\Resources\Cuentas\Pages\EditCuenta;
use App\Filament\Resources\Cuentas\Pages\ListCuentas;
use App\Filament\Resources\Cuentas\Schemas\CuentaForm;
use App\Filament\Resources\Cuentas\Tables\CuentasTable;
use App\Models\Cuenta;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CuentaResource extends Resource
{
    protected static ?string $model = Cuenta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'nombre_cuenta';

    protected static ?string $modelLabel = 'Cuenta';

    protected static ?string $pluralModelLabel = 'Cuentas';

    public static function form(Schema $schema): Schema
    {
        return CuentaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CuentasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCuentas::route('/'),
            'create' => CreateCuenta::route('/create'),
            'edit' => EditCuenta::route('/{record}/edit'),
        ];
    }
}

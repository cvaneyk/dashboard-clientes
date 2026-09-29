<?php

namespace App\Filament\Resources\PresupuestoEventos;

use App\Filament\Resources\PresupuestoEventos\Pages\CreatePresupuestoEvento;
use App\Filament\Resources\PresupuestoEventos\Pages\EditPresupuestoEvento;
use App\Filament\Resources\PresupuestoEventos\Pages\ListPresupuestoEventos;
use App\Filament\Resources\PresupuestoEventos\Schemas\PresupuestoEventoForm;
use App\Filament\Resources\PresupuestoEventos\Tables\PresupuestoEventosTable;
use App\Models\PresupuestoEvento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PresupuestoEventoResource extends Resource
{
    protected static ?string $model = PresupuestoEvento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'porcentaje';

    protected static ?string $modelLabel = 'Presupuesto';

    protected static ?string $pluralModelLabel = 'Presupuestos';

    public static function shouldRegisterNavigation(): bool
    {
        return false; // Se gestiona dentro de cada Línea (no como sección aparte).
    }

    public static function form(Schema $schema): Schema
    {
        return PresupuestoEventoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PresupuestoEventosTable::configure($table);
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
            'index' => ListPresupuestoEventos::route('/'),
            'create' => CreatePresupuestoEvento::route('/create'),
            'edit' => EditPresupuestoEvento::route('/{record}/edit'),
        ];
    }
}

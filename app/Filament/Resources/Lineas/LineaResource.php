<?php

namespace App\Filament\Resources\Lineas;

use App\Filament\Resources\Lineas\Pages\CreateLinea;
use App\Filament\Resources\Lineas\Pages\EditLinea;
use App\Filament\Resources\Lineas\Pages\ListLineas;
use App\Filament\Resources\Lineas\Schemas\LineaForm;
use App\Filament\Resources\Lineas\Tables\LineasTable;
use App\Models\Linea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LineaResource extends Resource
{
    protected static ?string $model = Linea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Linea';

    public static function form(Schema $schema): Schema
    {
        return LineaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LineasTable::configure($table);
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
            'index' => ListLineas::route('/'),
            'create' => CreateLinea::route('/create'),
            'edit' => EditLinea::route('/{record}/edit'),
        ];
    }
}

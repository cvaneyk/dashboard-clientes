<?php

namespace App\Filament\Resources\PlantillasEmail;

use App\Filament\Resources\PlantillasEmail\Pages\EditPlantillaEmail;
use App\Filament\Resources\PlantillasEmail\Pages\ListPlantillasEmail;
use App\Filament\Resources\PlantillasEmail\Schemas\PlantillaEmailForm;
use App\Filament\Resources\PlantillasEmail\Tables\PlantillasEmailTable;
use App\Models\PlantillaEmail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Textos de los emails periódicos. Las plantillas las define el sistema (cada
 * una la usa un informe concreto por su `clave`), así que aquí solo se editan:
 * ni se crean ni se borran.
 */
class PlantillaEmailResource extends Resource
{
    protected static ?string $model = PlantillaEmail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Plantilla de email';

    protected static ?string $pluralModelLabel = 'Plantillas de email';

    protected static ?string $slug = 'plantillas-email';

    public static function form(Schema $schema): Schema
    {
        return PlantillaEmailForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlantillasEmailTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlantillasEmail::route('/'),
            'edit'  => EditPlantillaEmail::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\MetricaDiarias;

use App\Filament\Resources\MetricaDiarias\Pages\CreateMetricaDiaria;
use App\Filament\Resources\MetricaDiarias\Pages\EditMetricaDiaria;
use App\Filament\Resources\MetricaDiarias\Pages\ListMetricaDiarias;
use App\Filament\Resources\MetricaDiarias\Schemas\MetricaDiariaForm;
use App\Filament\Resources\MetricaDiarias\Tables\MetricaDiariasTable;
use App\Models\MetricaDiaria;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MetricaDiariaResource extends Resource
{
    protected static ?string $model = MetricaDiaria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'fecha';

    protected static ?string $modelLabel = 'Métrica diaria';

    protected static ?string $pluralModelLabel = 'Métricas diarias';

    public static function canCreate(): bool
    {
        return false; // Las métricas entran por el importador de CSV, no a mano.
    }

    public static function form(Schema $schema): Schema
    {
        return MetricaDiariaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetricaDiariasTable::configure($table);
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
            'index' => ListMetricaDiarias::route('/'),
            'create' => CreateMetricaDiaria::route('/create'),
            'edit' => EditMetricaDiaria::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\MetricaDiarias\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MetricaDiariaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cliente_id')
                    ->relationship('cliente', 'id')
                    ->required(),
                Select::make('cuenta_id')
                    ->relationship('cuenta', 'id')
                    ->required(),
                Select::make('linea_id')
                    ->relationship('linea', 'id'),
                TextInput::make('plataforma')
                    ->required(),
                TextInput::make('match_key'),
                DatePicker::make('fecha')
                    ->required(),
                TextInput::make('impresiones')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('alcance')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('clics')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('gasto_real')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('leads')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('extra')
                    ->columnSpanFull(),
                TextInput::make('import_id')
                    ->numeric(),
            ]);
    }
}

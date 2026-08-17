<?php

namespace App\Filament\Resources\PresupuestoEventos\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PresupuestoEventoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('linea_id')
                    ->relationship('linea', 'id')
                    ->required(),
                DatePicker::make('fecha_efectiva')
                    ->required(),
                TextInput::make('objetivo_mes')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('estado')
                    ->required()
                    ->default('activa'),
                TextInput::make('nota'),
            ]);
    }
}

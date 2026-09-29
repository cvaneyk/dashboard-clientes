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
                DatePicker::make('desde')
                    ->label('Desde')
                    ->required(),
                DatePicker::make('hasta')
                    ->label('Hasta (vacío = sin fin)')
                    ->afterOrEqual('desde'),
                TextInput::make('porcentaje')
                    ->label('Porcentaje sobre el gasto real')
                    ->required()
                    ->numeric()
                    ->suffix('%')
                    ->default(0),
                TextInput::make('estado')
                    ->required()
                    ->default('activa'),
                TextInput::make('nota'),
            ]);
    }
}

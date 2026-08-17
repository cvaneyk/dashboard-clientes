<?php

namespace App\Filament\Resources\Lineas\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LineaForm
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
                TextInput::make('plataforma')
                    ->required(),
                TextInput::make('nombre')
                    ->required(),
                TextInput::make('producto'),
                TextInput::make('match_key')
                    ->required(),
                TextInput::make('nivel')
                    ->required()
                    ->default('campania'),
                Toggle::make('activo')
                    ->required(),
            ]);
    }
}

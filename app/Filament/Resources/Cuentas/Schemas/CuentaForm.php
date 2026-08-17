<?php

namespace App\Filament\Resources\Cuentas\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CuentaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cliente_id')
                    ->relationship('cliente', 'id')
                    ->required(),
                TextInput::make('plataforma')
                    ->required(),
                TextInput::make('nombre_cuenta')
                    ->required(),
                TextInput::make('external_id'),
                Toggle::make('activo')
                    ->required(),
            ]);
    }
}

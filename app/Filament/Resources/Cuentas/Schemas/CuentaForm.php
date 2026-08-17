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
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('plataforma')
                    ->label('Plataforma')
                    ->options([
                        'meta'     => 'Meta Ads',
                        'google'   => 'Google Ads',
                        'tiktok'   => 'TikTok Ads',
                        'linkedin' => 'LinkedIn Ads',
                    ])
                    ->required(),
                TextInput::make('nombre_cuenta')
                    ->label('Nombre de la cuenta')
                    ->required(),
                TextInput::make('external_id')
                    ->label('ID de cuenta en la plataforma (opcional)'),
                Toggle::make('activo')
                    ->label('Activa')
                    ->default(true),
            ]);
    }
}

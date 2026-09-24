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
                    ->live()
                    ->required(),
                TextInput::make('nombre_cuenta')
                    ->label('Nombre de la cuenta')
                    ->required(),
                TextInput::make('external_id')
                    ->label('ID de cuenta en la plataforma (opcional)'),
                TextInput::make('tipos_conversion')
                    ->label('Qué cuenta como lead (opcional)')
                    ->placeholder(implode(',', (array) config('services.meta.tipos_conversion')))
                    ->helperText('Tipos de conversión de Meta separados por comas, por orden de prioridad: se usa el primero que aparezca en cada campaña. Vacío = la lista por defecto. Para ver cuáles devuelve esta cuenta: php artisan portal:meta-acciones {id}')
                    ->visible(fn ($get) => $get('plataforma') === 'meta')
                    ->columnSpanFull(),
                Toggle::make('activo')
                    ->label('Activa')
                    ->default(true),
            ]);
    }
}

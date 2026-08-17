<?php

namespace App\Filament\Resources\Lineas\Schemas;

use App\Models\Linea;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('cuenta_id')
                    ->label('Cuenta publicitaria')
                    ->relationship('cuenta', 'nombre_cuenta')
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
                    ->default('meta')
                    ->required(),
                TextInput::make('nombre')
                    ->label('Nombre de la campaña / adset')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('match_key', Linea::normalizar((string) $state));
                    }),
                TextInput::make('producto')
                    ->label('Producto (opcional)'),
                TextInput::make('match_key')
                    ->label('Clave de emparejamiento (con el CSV)')
                    ->required()
                    ->helperText('Se rellena sola a partir del nombre. Debe coincidir con el nombre en el CSV (sin emojis).'),
                Select::make('nivel')
                    ->label('Nivel')
                    ->options([
                        'campania' => 'Campaña',
                        'adset'    => 'Adset / conjunto de anuncios',
                    ])
                    ->default('campania')
                    ->required(),
                Toggle::make('activo')
                    ->label('Activa')
                    ->default(true),

                Repeater::make('presupuestoEventos')
                    ->relationship()
                    ->label('Presupuesto del mes y cambios (subir / bajar / pausar)')
                    ->helperText('Cada fila es un cambio con su fecha. El objetivo es lo que se factura al cliente ese mes; el sistema lo reparte por días.')
                    ->schema([
                        DatePicker::make('fecha_efectiva')
                            ->label('Desde (fecha)')
                            ->required(),
                        TextInput::make('objetivo_mes')
                            ->label('Objetivo cliente del mes (€)')
                            ->numeric()
                            ->required(),
                        Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'activa'   => 'Activa',
                                'pausada'  => 'Pausada',
                            ])
                            ->default('activa')
                            ->required(),
                        TextInput::make('nota')
                            ->label('Nota (opcional)'),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->columnSpanFull(),
            ]);
    }
}

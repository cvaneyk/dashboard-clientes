<?php

namespace App\Filament\Resources\Lineas\Schemas;

use App\Models\Linea;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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

                // Referencia interna: lo que esta campaña tiene topado en la
                // plataforma. Ayuda a fijar el objetivo, pero no es el objetivo.
                Placeholder::make('presupuesto_plataforma_ref')
                    ->label('Tope en la plataforma (referencia interna)')
                    ->content(function (?Linea $record): string {
                        if (! $record?->presupuesto_plataforma) {
                            return 'Sin dato. Se rellena solo al sincronizar por API.';
                        }

                        return sprintf(
                            '%s € %s%s · actualizado el %s',
                            number_format((float) $record->presupuesto_plataforma, 2, ',', '.'),
                            $record->presupuesto_plataforma_tipo === 'diario' ? 'al día' : 'del periodo',
                            $record->presupuesto_plataforma_nivel === 'adsets' ? ' (suma de sus adsets)' : '',
                            $record->presupuesto_plataforma_actualizado_en?->format('d/m/Y H:i') ?? '?'
                        );
                    })
                    ->helperText('Es el tope de gasto en Meta, no lo que se factura al cliente. Nunca se muestra en el dashboard.')
                    ->visible(fn (?Linea $record) => $record !== null)
                    ->columnSpanFull(),

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

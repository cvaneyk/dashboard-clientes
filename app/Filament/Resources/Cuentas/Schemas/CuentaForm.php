<?php

namespace App\Filament\Resources\Cuentas\Schemas;

use App\Models\Cuenta;
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
                    ->options(Cuenta::PLATAFORMAS)
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
                    ->helperText(fn ($get) => $get('plataforma') === 'linkedin'
                        ? 'Campos de conversión de LinkedIn separados por comas, por orden de prioridad: se usa el primero con valor. Por defecto oneClickLeads (formularios) y luego externalWebsiteConversions (Insight Tag).'
                        : 'Tipos de conversión de Meta separados por comas, por orden de prioridad: se usa el primero que aparezca en cada campaña. Vacío = la lista por defecto. Para ver cuáles devuelve esta cuenta: php artisan portal:meta-acciones {id}')
                    ->visible(fn ($get) => in_array($get('plataforma'), ['meta', 'linkedin'], true))
                    ->columnSpanFull(),
                Toggle::make('activo')
                    ->label('Activa')
                    ->default(true),
            ]);
    }
}

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
                    ->label('ID de cuenta en la plataforma (opcional)')
                    ->helperText(fn ($get) => match ($get('plataforma')) {
                        'spotify' => 'El UUID de la cuenta publicitaria, el que aparece en la URL de Spotify Ads Manager.',
                        'openai'  => 'Opcional: si lo dejas vacío se rellena al conectar con el de la clave de API.',
                        default   => null,
                    }),
                TextInput::make('tipos_conversion')
                    ->label('Qué cuenta como lead (opcional)')
                    ->placeholder(fn ($get) => implode(',', (array) config(match ($get('plataforma')) {
                        'linkedin' => 'services.linkedin_ads.tipos_conversion',
                        'spotify'  => 'services.spotify_ads.tipos_conversion',
                        default    => 'services.meta.tipos_conversion',
                    })))
                    ->helperText(fn ($get) => match ($get('plataforma')) {
                        'linkedin' => 'Campos de conversión de LinkedIn separados por comas, por orden de prioridad: se usa el primero con valor. Por defecto oneClickLeads (formularios) y luego externalWebsiteConversions (Insight Tag).',
                        'spotify'  => 'Campos de conversión de Spotify separados por comas, por orden de prioridad: se usa el primero con valor. Valen LEADS, SIGN_UPS, PURCHASES, ADD_TO_CART, START_CHECKOUT, PAGE_VIEWS y CUSTOM_EVENT_1…5. Requieren el píxel de Spotify en la web.',
                        default    => 'Tipos de conversión de Meta separados por comas, por orden de prioridad: se usa el primero que aparezca en cada campaña. Vacío = la lista por defecto. Para ver cuáles devuelve esta cuenta: php artisan portal:meta-acciones {id}',
                    })
                    ->visible(fn ($get) => in_array($get('plataforma'), ['meta', 'linkedin', 'spotify'], true))
                    ->columnSpanFull(),
                Toggle::make('activo')
                    ->label('Activa')
                    ->default(true),
            ]);
    }
}

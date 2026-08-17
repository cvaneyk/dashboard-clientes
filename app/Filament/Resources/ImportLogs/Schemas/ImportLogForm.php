<?php

namespace App\Filament\Resources\ImportLogs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ImportLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cliente_id')
                    ->numeric(),
                TextInput::make('plataforma')
                    ->required(),
                TextInput::make('fichero'),
                DatePicker::make('periodo_inicio'),
                DatePicker::make('periodo_fin'),
                TextInput::make('filas_ok')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('filas_error')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('resumen')
                    ->columnSpanFull(),
                TextInput::make('user_id')
                    ->numeric(),
            ]);
    }
}

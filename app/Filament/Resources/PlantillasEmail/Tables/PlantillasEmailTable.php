<?php

namespace App\Filament\Resources\PlantillasEmail\Tables;

use App\Support\AgenciaActual;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlantillasEmailTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Plantilla'),
                // El superadmin ve las de todas las agencias, y las que no
                // tienen agencia son las que se copian a cada agencia nueva.
                TextColumn::make('agencia.nombre')
                    ->label('Agencia')
                    ->placeholder('Por defecto (para agencias nuevas)')
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                TextColumn::make('asunto')
                    ->label('Asunto')
                    ->limit(70),
                TextColumn::make('updated_at')
                    ->label('Última edición')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}

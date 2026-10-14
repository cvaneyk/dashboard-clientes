<?php

namespace App\Filament\Resources\Agencias\Tables;

use App\Support\AgenciaActual;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgenciasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Agencia')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('clientes_count')
                    ->label('Clientes')
                    ->counts('clientes')
                    ->badge(),
                TextColumn::make('usuarios_count')
                    ->label('Usuarios')
                    ->counts('usuarios')
                    ->badge(),
                IconColumn::make('activo')
                    ->label('Activa')
                    ->boolean()
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Lineas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LineasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nombre')
                    ->label('Campaña / adset')
                    ->searchable(),
                TextColumn::make('producto')
                    ->label('Producto')
                    ->toggleable(),
                TextColumn::make('plataforma')
                    ->label('Plataforma')
                    ->badge(),
                TextColumn::make('nivel')
                    ->label('Nivel')
                    ->badge()
                    ->toggleable(),
                IconColumn::make('activo')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('cliente.nombre')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

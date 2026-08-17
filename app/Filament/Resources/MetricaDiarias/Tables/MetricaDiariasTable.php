<?php

namespace App\Filament\Resources\MetricaDiarias\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MetricaDiariasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cliente.id')
                    ->searchable(),
                TextColumn::make('cuenta.id')
                    ->searchable(),
                TextColumn::make('linea.id')
                    ->searchable(),
                TextColumn::make('plataforma')
                    ->searchable(),
                TextColumn::make('match_key')
                    ->searchable(),
                TextColumn::make('fecha')
                    ->date()
                    ->sortable(),
                TextColumn::make('impresiones')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('alcance')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('clics')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('gasto_real')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('leads')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('import_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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

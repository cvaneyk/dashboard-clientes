<?php

namespace App\Filament\Resources\PresupuestoEventos\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PresupuestoEventosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('linea.id')
                    ->searchable(),
                TextColumn::make('desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('sin fin')
                    ->sortable(),
                TextColumn::make('porcentaje')
                    ->label('Margen')
                    ->formatStateUsing(fn ($state) => '+' . number_format((float) $state, 2, ',', '.') . ' %')
                    ->sortable(),
                TextColumn::make('estado')
                    ->searchable(),
                TextColumn::make('nota')
                    ->searchable(),
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

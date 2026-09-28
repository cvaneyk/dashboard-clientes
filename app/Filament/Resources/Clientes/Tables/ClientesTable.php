<?php

namespace App\Filament\Resources\Clientes\Tables;

use App\Models\Cliente;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('lineas_count')
                    ->label('Líneas')
                    ->counts('lineas')
                    ->badge(),
                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->recordActions([
                EditAction::make(),
                // Abre el dashboard tal y como lo ve ese cliente. Útil para
                // revisar antes de una reunión sin pedirle sus credenciales.
                Action::make('verDashboard')
                    ->label('Ver dashboard')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color('gray')
                    ->url(fn (Cliente $record) => route('portal.dashboard', ['cliente' => $record->slug]))
                    ->openUrlInNewTab()
                    ->visible(fn (Cliente $record) => $record->activo),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

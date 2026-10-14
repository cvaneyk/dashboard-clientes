<?php

namespace App\Filament\Resources\Users\Tables;

use App\Support\AgenciaActual;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->getStateUsing(fn ($record) => match (true) {
                        (bool) $record->es_superadmin => 'Superadmin',
                        (bool) $record->es_admin      => 'Administrador',
                        default                       => 'Cliente',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Superadmin'    => 'danger',
                        'Administrador' => 'warning',
                        default         => 'success',
                    }),
                TextColumn::make('agencia.nombre')
                    ->label('Agencia')
                    ->placeholder('—')
                    ->sortable()
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('cliente.nombre')
                    ->label('Cliente asignado')
                    ->placeholder('— (usuario interno)')
                    ->searchable(),
            ])
            ->filters([
                TernaryFilter::make('es_admin')
                    ->label('Tipo de usuario')
                    ->placeholder('Todos')
                    ->trueLabel('Solo administradores')
                    ->falseLabel('Solo clientes'),
            ])
            ->defaultSort('es_admin', 'desc')
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

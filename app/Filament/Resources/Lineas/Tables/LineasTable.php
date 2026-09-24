<?php

namespace App\Filament\Resources\Lineas\Tables;

use App\Models\Linea;
use App\Models\PresupuestoEvento;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LineasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Los presupuestos se leen fila a fila para la columna de objetivo:
            // sin esto sería una consulta por línea.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('presupuestoEventos'))
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

                // Lo que se le factura al cliente: el objetivo vigente hoy.
                // Sin él, esa campaña sale a 0 € en el dashboard del cliente.
                TextColumn::make('objetivo')
                    ->label('Objetivo cliente')
                    ->state(function (Linea $record): string {
                        $evento = $record->eventoVigente();

                        if (! $evento) {
                            return 'Sin presupuesto';
                        }

                        if ($evento->estado === 'pausada') {
                            return 'Pausada';
                        }

                        return number_format((float) $evento->objetivo_mes, 2, ',', '.') . ' € / mes';
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Sin presupuesto' => 'danger',
                        'Pausada'         => 'warning',
                        default           => 'success',
                    }),

                // Referencia interna traída de la plataforma. No es lo que se
                // factura: sirve para decidir el objetivo con criterio.
                TextColumn::make('presupuesto_plataforma')
                    ->label('Tope en la plataforma')
                    ->money('EUR')
                    ->placeholder('—')
                    ->description(fn (Linea $record): ?string => $record->presupuesto_plataforma === null
                        ? null
                        : trim(($record->presupuesto_plataforma_tipo === 'diario' ? 'al día' : 'del periodo')
                            . ($record->presupuesto_plataforma_nivel === 'adsets' ? ' · suma de adsets' : '')))
                    ->toggleable(),

                IconColumn::make('activo')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Filter::make('sin_presupuesto')
                    ->label('Solo las que no tienen presupuesto')
                    ->query(fn (Builder $query) => $query->whereDoesntHave(
                        'presupuestoEventos',
                        fn (Builder $eventos) => $eventos->whereDate('fecha_efectiva', '<=', now()->toDateString())
                    )),
            ])
            ->defaultSort('cliente.nombre')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::asignarObjetivo(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Alta rápida del objetivo del mes para varias líneas de golpe: es lo que
     * hace llevadero que la sincronización diaria vaya creando campañas nuevas.
     *
     * Añade un evento de presupuesto a cada línea seleccionada (no edita el
     * presupuesto "en sitio": el histórico se respeta, como manda el motor).
     * Si se repite con la misma fecha, corrige ese evento en vez de duplicarlo.
     */
    private static function asignarObjetivo(): BulkAction
    {
        return BulkAction::make('asignar_objetivo')
            ->label('Asignar objetivo del mes')
            ->icon('heroicon-o-banknotes')
            ->modalHeading('Objetivo del mes para las líneas seleccionadas')
            ->modalDescription('Es lo que se le factura al cliente, no lo que se paga a la plataforma. El sistema lo reparte por días.')
            ->modalSubmitActionLabel('Asignar')
            ->schema([
                DatePicker::make('fecha_efectiva')
                    ->label('Desde (fecha)')
                    ->default(now()->startOfMonth())
                    ->required()
                    ->helperText('Normalmente el día 1 del mes. Si es a mitad de mes, el reparto se renivela solo.'),
                TextInput::make('objetivo_mes')
                    ->label('Objetivo cliente del mes (€)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->helperText('Se aplica el mismo importe a cada línea seleccionada.'),
                Select::make('estado')
                    ->label('Estado')
                    ->options([
                        'activa'  => 'Activa',
                        'pausada' => 'Pausada',
                    ])
                    ->default('activa')
                    ->required(),
                TextInput::make('nota')
                    ->label('Nota (opcional)'),
            ])
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $linea) {
                    PresupuestoEvento::updateOrCreate(
                        [
                            'linea_id'       => $linea->id,
                            'fecha_efectiva' => $data['fecha_efectiva'],
                        ],
                        [
                            'objetivo_mes' => $data['objetivo_mes'],
                            'estado'       => $data['estado'],
                            'nota'         => $data['nota'] ?? null,
                        ]
                    );
                }

                Notification::make()
                    ->title('Objetivo asignado')
                    ->body(sprintf('%d líneas actualizadas desde el %s.', $records->count(), $data['fecha_efectiva']))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}

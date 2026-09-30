<?php

namespace App\Filament\Resources\Lineas\Tables;

use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\PresupuestoEvento;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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

                // Margen que se aplica hoy. Sin tramo vigente, esa campaña sale
                // a 0 € en el dashboard del cliente: no hay con qué facturarla.
                TextColumn::make('objetivo')
                    ->label('Margen aplicado')
                    ->state(function (Linea $record): string {
                        $tramo = $record->eventoVigente();

                        if (! $tramo) {
                            return 'Sin tramo';
                        }

                        if ($tramo->estado === 'pausada') {
                            return 'Pausada';
                        }

                        return '+' . number_format((float) $tramo->porcentaje, 2, ',', '.') . ' %';
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Sin tramo' => 'danger',
                        'Pausada'   => 'warning',
                        default     => 'success',
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
            // Filtrar y luego "seleccionar todo" es lo que permite asignar
            // tramos a todas las líneas de un cliente o de una cuenta de golpe.
            ->filters([
                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('cuenta_id')
                    ->label('Cuenta')
                    ->relationship('cuenta', 'nombre_cuenta')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('plataforma')
                    ->label('Plataforma')
                    ->options(Cuenta::PLATAFORMAS),
                Filter::make('sin_presupuesto')
                    ->label('Solo las que no tienen tramo vigente')
                    ->query(fn (Builder $query) => $query->whereDoesntHave(
                        'presupuestoEventos',
                        fn (Builder $tramos) => $tramos
                            ->whereDate('desde', '<=', now()->toDateString())
                            ->where(fn (Builder $q) => $q
                                ->whereNull('hasta')
                                ->orWhereDate('hasta', '>=', now()->toDateString()))
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
            ->label('Asignar tramo de facturación')
            ->icon('heroicon-o-banknotes')
            ->modalHeading('Tramo de facturación para las líneas seleccionadas')
            ->modalDescription('Al cliente se le factura el gasto real de la plataforma más este porcentaje, entre las dos fechas indicadas.')
            ->modalSubmitActionLabel('Asignar')
            ->schema(fn (Collection $records) => [
                DatePicker::make('desde')
                    ->label('Desde')
                    ->default(now()->startOfMonth())
                    ->required()
                    ->live(onBlur: true),
                DatePicker::make('hasta')
                    ->label('Hasta (vacío = sin fin)')
                    ->afterOrEqual('desde')
                    ->live(onBlur: true)
                    ->helperText('Déjalo vacío para que el tramo siga vigente hasta que lo sustituyas por otro.'),

                // Aviso de solape, recalculado al cambiar las fechas.
                Placeholder::make('aviso_solape')
                    ->label('')
                    ->content(fn (Get $get) => new HtmlString(self::avisoSolape($records, $get('desde'), $get('hasta'))))
                    ->visible(fn (Get $get) => self::solapes($records, $get('desde'), $get('hasta')) !== [])
                    ->columnSpanFull(),

                // Puerta explícita: solo aparece (y solo es obligatoria) cuando
                // de verdad hay solape, para no estorbar en el caso normal.
                Checkbox::make('confirmar_solape')
                    ->label('Entiendo que se solapa y quiero añadirlo igualmente')
                    ->accepted()
                    ->visible(fn (Get $get) => self::solapes($records, $get('desde'), $get('hasta')) !== [])
                    ->columnSpanFull(),
                TextInput::make('porcentaje')
                    ->label('Porcentaje sobre el gasto (%)')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('%')
                    ->required()
                    ->helperText('25 = gasto real más un 25 %. Se aplica el mismo a todas las líneas seleccionadas.'),
                Select::make('estado')
                    ->label('Estado')
                    ->options([
                        'activa'  => 'Activa',
                        'pausada' => 'Pausada',
                    ])
                    ->default('activa')
                    ->required(),
                TextInput::make('nota')
                    ->label('Nota (opcional)')
                    ->columnSpanFull(),
            ])
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $linea) {
                    // Repetir la acción con las mismas fechas corrige ese tramo
                    // en vez de acumular duplicados sobre la misma línea.
                    PresupuestoEvento::updateOrCreate(
                        [
                            'linea_id' => $linea->id,
                            'desde'    => $data['desde'],
                            'hasta'    => $data['hasta'] ?? null,
                        ],
                        [
                            'porcentaje' => $data['porcentaje'],
                            'estado'     => $data['estado'],
                            'nota'       => $data['nota'] ?? null,
                        ]
                    );
                }

                Notification::make()
                    ->title('Tramo asignado')
                    ->body(sprintf(
                        '%d líneas facturarán el gasto real +%s %% desde el %s%s.',
                        $records->count(),
                        $data['porcentaje'],
                        $data['desde'],
                        $data['hasta'] ? ' hasta el ' . $data['hasta'] : ''
                    ))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Líneas seleccionadas que ya tienen un tramo pisando ese rango de fechas.
     *
     * @param  Collection<int,Linea>  $lineas
     * @return array<int,array{linea:string,porcentaje:float}>
     */
    public static function solapes(Collection $lineas, ?string $desde, ?string $hasta): array
    {
        if (! $desde) {
            return [];
        }

        $ini = Carbon::parse($desde)->startOfDay();
        $fin = $hasta ? Carbon::parse($hasta)->startOfDay() : null;

        $out = [];

        foreach ($lineas as $linea) {
            foreach ($linea->presupuestoEventos as $tramo) {
                if (! $tramo->desde) {
                    continue;
                }

                // Un tramo con exactamente las mismas fechas no es un solape:
                // la acción lo actualiza en sitio en vez de crear otro. Avisar
                // aquí convertiría "corregir el porcentaje" en un falso positivo.
                $mismasFechas = $tramo->desde->equalTo($ini)
                    && (($fin === null && $tramo->hasta === null)
                        || ($fin !== null && $tramo->hasta !== null && $tramo->hasta->equalTo($fin)));

                if ($mismasFechas) {
                    continue;
                }

                // Dos intervalos se solapan si cada uno empieza antes de que el
                // otro acabe. Sin fecha de fin, el tramo llega hasta el infinito.
                $empiezaAntesDeQueAcabeElNuevo = ! $fin || $tramo->desde->lte($fin);
                $acabaDespuesDeQueEmpieceElNuevo = ! $tramo->hasta || $tramo->hasta->gte($ini);

                if ($empiezaAntesDeQueAcabeElNuevo && $acabaDespuesDeQueEmpieceElNuevo) {
                    $out[] = [
                        // El nombre de la campaña, no el producto: varias líneas
                        // comparten producto y entonces no se sabría cuál es cuál.
                        'linea'      => $linea->nombre,
                        'porcentaje' => (float) $tramo->porcentaje,
                    ];

                    continue 2; // con un solape por línea basta para avisar
                }
            }
        }

        return $out;
    }

    /** Texto del aviso, con las líneas afectadas y lo que va a pasar. */
    public static function avisoSolape(Collection $lineas, ?string $desde, ?string $hasta): string
    {
        $solapes = self::solapes($lineas, $desde, $hasta);

        if ($solapes === []) {
            return '';
        }

        $detalle = collect($solapes)
            ->map(fn (array $s) => e($s['linea']) . ' (+' . number_format($s['porcentaje'], 2, ',', '.') . ' %)')
            ->take(8)
            ->implode(', ');

        $resto = count($solapes) > 8 ? ' y ' . (count($solapes) - 8) . ' más' : '';

        // El color del texto va explícito: sin él hereda el blanco del tema
        // oscuro de Filament y queda ilegible sobre el fondo claro del aviso.
        // La regla .dark lo adapta; si no casara, el caso por defecto sigue
        // siendo legible (fondo claro con texto oscuro).
        return '<style>'
            . '.aviso-solape{border-left:4px solid #b45309;background:#fffbeb;color:#713f12;padding:10px 12px;border-radius:6px}'
            . '.dark .aviso-solape{background:#422006;color:#fef3c7;border-left-color:#f59e0b}'
            . '</style>'
            . '<div class="aviso-solape">'
            . '<strong>' . count($solapes) . ' de las líneas seleccionadas ya tienen un tramo en esas fechas:</strong><br>'
            . $detalle . $resto . '.<br><br>'
            . 'Si continúas se aplicará <strong>el porcentaje más alto</strong> de los que se solapen. '
            . 'Para <em>bajar</em> el margen no basta con añadir este tramo: hay que corregir o borrar el anterior.'
            . '</div>';
    }
}

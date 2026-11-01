<?php

namespace App\Services\Informes;

use App\Models\Agencia;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\MetricaDiaria;
use App\Models\PlantillaEmail;
use App\Models\User;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Construye los informes semanales por email: uno por cliente y un resumen
 * interno con todas las cuentas para administración.
 *
 * REGLA QUE NO SE PUEDE ROMPER: el informe del cliente sale del motor de
 * precios (coste-cliente) igual que su dashboard. gasto_real y el margen solo
 * aparecen en el resumen interno, que nunca se manda a un email de cliente.
 */
class InformesSemanales
{
    /** Semanas que entran en el gráfico de evolución (la informada incluida). */
    private const SEMANAS = 6;

    public function __construct(private PricingEngine $engine) {}

    /**
     * Semana cerrada anterior a la fecha dada (lunes a domingo, hora de Madrid).
     * Un lunes cualquiera devuelve la semana que acaba de terminar.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function semanaAnterior(?Carbon $referencia = null): array
    {
        $ref   = ($referencia ?? Carbon::now('Europe/Madrid'))->copy();
        $lunes = Carbon::parse($ref->toDateString())->startOfWeek(Carbon::MONDAY)->subWeek();

        return [$lunes, $lunes->copy()->addDays(6)];
    }

    /**
     * @return array{asunto:string,cuerpo:string,sin_actividad:bool}
     */
    public function paraCliente(Cliente $cliente, Carbon $desde, Carbon $hasta): array
    {
        // Las mismas líneas que pinta el dashboard.
        $lineas = $cliente->lineas()->where('activo', true)->with('presupuestoEventos')->get();

        [$antDesde, $antHasta] = [$desde->copy()->subWeek(), $hasta->copy()->subWeek()];

        $actual   = $this->engine->kpis($lineas, $desde, $hasta);
        $anterior = $this->engine->kpis($lineas, $antDesde, $antHasta);

        $canales = $lineas->groupBy('plataforma')
            ->map(fn (Collection $ls, string $plat) => [
                'plataforma' => $plat,
                'canal'      => Cuenta::PLATAFORMAS[$plat] ?? $plat,
            ] + $this->engine->kpis($ls, $desde, $hasta))
            ->filter(fn ($k) => $k['coste'] > 0 || $k['clics'] > 0 || $k['impresiones'] > 0)
            ->sortByDesc('coste')
            ->values();

        $texto = [
            'cliente'          => $cliente->nombre,
            'periodo'          => self::periodo($desde, $hasta),
            'desde'            => $desde->format('d/m/Y'),
            'hasta'            => $hasta->format('d/m/Y'),
            'inversion'        => self::euros($actual['coste']),
            'impresiones'      => self::entero($actual['impresiones']),
            'clics'            => self::entero($actual['clics']),
            'leads'            => self::numero($actual['leads']),
            'ctr'              => self::numero($actual['ctr']) . ' %',
            'cpc'              => self::euros($actual['cpc']),
            'cpl'              => self::euros($actual['cpl']),
            'var_inversion'    => self::variacion($actual['coste'], $anterior['coste']),
            'var_clics'        => self::variacion($actual['clics'], $anterior['clics']),
            'var_leads'        => self::variacion($actual['leads'], $anterior['leads']),
            'enlace_dashboard' => route('portal.dashboard', ['cliente' => $cliente->slug]),
        ];

        $html = [
            'tabla_kpis'         => $this->tablaKpisCliente($actual, $anterior),
            'tabla_canales'      => $this->tablaCanales($canales),
            'comentario_kpis'    => Diagnostico::html(Diagnostico::kpis($actual, $anterior)),
            'comentario_canales' => Diagnostico::html(Diagnostico::canales($canales, $actual)),
            'comentario_embudo'  => Diagnostico::html(Diagnostico::embudo($actual, $anterior)),
            'grafico_canales'    => $this->graficoCanales($canales),
            'grafico_semanas'    => $this->graficoSemanas($this->serieSemanal($lineas, $hasta, self::SEMANAS)),
            'embudo'             => $this->embudo($actual),
            'nota'               => self::nota($cliente, $desde),
        ];

        // Los textos de la agencia del cliente (o los por defecto).
        $render = PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL, $cliente->agencia_id)->render($texto, $html);

        return $render + [
            'sin_actividad' => $actual['coste'] == 0 && $actual['clics'] == 0 && $actual['impresiones'] == 0,
            'agencia_id'    => $cliente->agencia_id,
        ];
    }

    /**
     * Resumen interno de las cuentas de UNA agencia (null = las que no tienen
     * agencia). Aquí SÍ va el gasto real y el margen: es para el account
     * manager de esa agencia, nunca para un cliente ni para otra agencia.
     *
     * @return array{asunto:string,cuerpo:string,agencia_id:?int}
     */
    public function paraAdmin(Carbon $desde, Carbon $hasta, ?int $agenciaId = null): array
    {
        // where(col, null) es "IS NULL": sin agencia, solo los clientes sin agencia.
        $cuentas = Cuenta::with(['cliente', 'conexionApi', 'lineas.presupuestoEventos'])
            ->whereHas('cliente', fn ($q) => $q->where('activo', true)->where('agencia_id', $agenciaId))
            ->get()
            ->sortBy(fn (Cuenta $c) => $c->cliente->nombre . ' ' . $c->plataforma);

        $reales = MetricaDiaria::whereIn('cuenta_id', $cuentas->pluck('id'))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('linea_id, SUM(gasto_real) gasto, SUM(clics) clics, SUM(leads) leads')
            ->groupBy('linea_id')
            ->get()
            ->keyBy('linea_id');

        $filas    = [];
        $sinMargen = [];
        $total    = ['gasto' => 0.0, 'facturado' => 0.0, 'clics' => 0, 'leads' => 0.0];

        foreach ($cuentas as $cuenta) {
            $fila = ['gasto' => 0.0, 'facturado' => 0.0, 'clics' => 0, 'leads' => 0.0, 'sin_margen' => 0.0];

            foreach ($cuenta->lineas as $linea) {
                $r = $reales->get($linea->id);
                if (! $r) {
                    continue;
                }
                $gasto     = (float) $r->gasto;
                $facturado = $this->engine->costeClientePeriodo($linea, $desde, $hasta);

                $fila['gasto']     += $gasto;
                $fila['facturado'] += $facturado;
                $fila['clics']     += (int) $r->clics;
                $fila['leads']     += (float) $r->leads;

                // Gasto que se repercute al coste, sin ganar nada: falta tramo,
                // el margen está en pausa o es del 0 %.
                if ($gasto > 0 && $facturado <= $gasto + 0.005) {
                    $fila['sin_margen'] += $gasto;
                    $sinMargen[] = $cuenta->cliente->nombre . ' · ' . ($linea->producto ?: $linea->nombre)
                        . ' (' . self::euros($gasto) . ' de gasto)';
                }
            }

            if ($fila['gasto'] == 0 && $fila['clics'] == 0) {
                continue;
            }

            foreach (['gasto', 'facturado', 'clics', 'leads'] as $k) {
                $total[$k] += $fila[$k];
            }

            $filas[] = $fila + [
                'cliente'    => $cuenta->cliente->nombre,
                'cuenta'     => $cuenta->nombre_cuenta,
                'plataforma' => Cuenta::PLATAFORMAS[$cuenta->plataforma] ?? $cuenta->plataforma,
            ];
        }

        $avisos = [];
        foreach ($cuentas as $cuenta) {
            if ($cuenta->conexionApi?->ultimo_error) {
                $avisos[] = 'Error de sincronización en ' . $cuenta->cliente->nombre . ' · '
                    . $cuenta->nombre_cuenta . ': ' . $cuenta->conexionApi->ultimo_error;
            }
        }
        foreach ($sinMargen as $s) {
            $avisos[] = 'Gasto sin margen aplicado: ' . $s;
        }
        foreach (Cliente::where('activo', true)->where('informe_semanal', true)->where('agencia_id', $agenciaId)->get() as $c) {
            if (! $c->emailsInforme()) {
                $avisos[] = 'El cliente ' . $c->nombre . ' tiene el informe semanal activado pero ningún email válido.';
            }
        }

        // Las filas van por cuenta; el gráfico y el diagnóstico hablan de
        // clientes, así que se agrupan aquí en vez de repetir la consulta.
        $porCliente = [];
        foreach ($filas as $f) {
            $porCliente[$f['cliente']] ??= [
                'cliente' => $f['cliente'], 'gasto' => 0.0, 'facturado' => 0.0, 'sin_margen' => 0.0,
            ];
            $porCliente[$f['cliente']]['gasto']        += $f['gasto'];
            $porCliente[$f['cliente']]['facturado']    += $f['facturado'];
            $porCliente[$f['cliente']]['sin_margen']   += $f['sin_margen'];
        }
        $porCliente = array_values($porCliente);

        $texto = [
            'periodo'   => self::periodo($desde, $hasta),
            'desde'     => $desde->format('d/m/Y'),
            'hasta'     => $hasta->format('d/m/Y'),
            'gasto'     => self::euros($total['gasto']),
            'facturado' => self::euros($total['facturado']),
            'margen'    => self::euros($total['facturado'] - $total['gasto']),
        ];

        $html = [
            'tabla_kpis'      => $this->tablaKpisAdmin($total),
            'tabla_cuentas'   => $this->tablaCuentas($filas),
            'diagnostico'     => Diagnostico::html(Diagnostico::paraAdmin($total, $porCliente)),
            'grafico_clientes' => $this->graficoClientes($porCliente),
            'grafico_semanas' => $this->graficoSemanas(
                $this->serieGasto($hasta, self::SEMANAS, $cuentas->flatMap(fn (Cuenta $c) => $c->lineas->pluck('id'))),
                'gasto real',
            ),
            'avisos'          => $avisos
                ? '<ul>' . implode('', array_map(fn ($a) => '<li>' . e($a) . '</li>', $avisos)) . '</ul>'
                : '<p>Sin avisos esta semana.</p>',
        ];

        return PlantillaEmail::deClave(PlantillaEmail::INFORME_ADMIN, $agenciaId)->render($texto, $html)
            + ['agencia_id' => $agenciaId];
    }

    /**
     * Destinatarios del resumen interno de una agencia: el email de informes de
     * su ficha (uno o varios); si no tiene, sus usuarios admin. Sin agencia
     * (instalación anterior a las agencias): INFORME_ADMIN_EMAIL del .env o,
     * si está vacío, los admins sin agencia.
     *
     * @return list<string>
     */
    public static function destinatariosAdmin(?int $agenciaId = null): array
    {
        $configurados = $agenciaId
            ? (string) Agencia::withoutGlobalScopes()->whereKey($agenciaId)->value('email_informes')
            : config('services.informes.admin');

        return Cliente::separarEmails($configurados) ?: User::where('es_admin', true)
            ->where('agencia_id', $agenciaId)
            ->pluck('email')
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    // ------------------------------------------------------------- gráficos

    /**
     * Barras de inversión y de leads por canal. El canal que se lleva una parte
     * apreciable del dinero sin devolver ningún lead se marca en rojo: es lo
     * que hay que ver sin leer nada.
     *
     * @param  Collection<int,array>  $canales
     */
    private function graficoCanales(Collection $canales): string
    {
        if ($canales->isEmpty()) {
            return '<p style="font-size:14px;color:#6b7280;">Sin actividad en esta semana.</p>';
        }

        $total = (float) $canales->sum('coste');

        $inversion = $canales->map(fn ($c) => [
            'etiqueta' => $c['canal'],
            'valor'    => $c['coste'],
            'texto'    => self::euros($c['coste']),
            'color'    => Graficos::color($c['plataforma'] ?? null),
            'alerta'   => $c['leads'] == 0 && $total > 0 && $c['coste'] / $total >= 0.2,
        ])->all();

        $leads = $canales->sortByDesc('leads')->map(fn ($c) => [
            'etiqueta' => $c['canal'],
            'valor'    => $c['leads'],
            'texto'    => self::numero($c['leads']),
            'color'    => Graficos::color($c['plataforma'] ?? null),
            'alerta'   => $c['leads'] == 0 && $c['coste'] > 0,
        ])->values()->all();

        return self::rotulo('Inversión por canal') . Graficos::barras($inversion)
            . self::rotulo('Leads por canal') . Graficos::barras($leads);
    }

    /**
     * Evolución de las últimas semanas. La barra es el importe, porque siempre
     * hay importe; los leads y el CPL van en el texto. Si la barra fuera el CPL,
     * una semana sin leads saldría vacía y parecería un fallo del gráfico.
     *
     * @param  list<array{etiqueta:string,coste:float,leads:float,cpl:float}>  $serie
     */
    private function graficoSemanas(array $serie, string $queMide = 'inversión'): string
    {
        if (! $serie) {
            return '';
        }

        $ultima = count($serie) - 1;
        $filas  = [];

        foreach ($serie as $i => $s) {
            $filas[] = [
                'etiqueta' => $s['etiqueta'] . ($i === $ultima ? ' (esta)' : ''),
                'valor'    => $s['coste'],
                'texto'    => self::euros($s['coste']) . ' · ' . self::numero($s['leads']) . ' leads',
            ];
        }

        return self::rotulo('Últimas ' . count($serie) . ' semanas · ' . $queMide)
            . Graficos::barras($filas, '#638E76');
    }

    /** Impresiones → clics → leads, con lo que pasa de cada paso al siguiente. */
    private function embudo(array $a): string
    {
        if ($a['impresiones'] == 0 && $a['clics'] == 0) {
            return '';
        }

        $ctr    = $a['impresiones'] > 0 ? $a['clics'] / $a['impresiones'] * 100 : 0.0;
        $aLeads = $a['clics'] > 0 ? $a['leads'] / $a['clics'] * 100 : 0.0;

        return Graficos::embudo([
            [
                'nombre' => 'Se mostró el anuncio',
                'valor'  => self::entero($a['impresiones']),
                'paso'   => self::numero($ctr) . ' % hace clic',
            ],
            [
                'nombre' => 'Entraron en la web',
                'valor'  => self::entero($a['clics']),
                'paso'   => self::numero($aLeads) . ' % contacta',
            ],
            [
                'nombre' => 'Contactaron',
                'valor'  => self::numero($a['leads']),
            ],
        ]);
    }

    /**
     * Margen por cliente, solo para el informe interno.
     *
     * @param  list<array{cliente:string,gasto:float,facturado:float,sin_margen:float}>  $porCliente
     */
    private function graficoClientes(array $porCliente): string
    {
        if (! $porCliente) {
            return '';
        }

        $filas = [];

        foreach ($porCliente as $f) {
            $margen = $f['facturado'] - $f['gasto'];
            $texto  = self::euros($margen);

            if ($f['sin_margen'] > 0) {
                $texto .= ' · ' . self::euros($f['sin_margen']) . ' de gasto sin margen';
            }

            $filas[] = [
                'etiqueta' => $f['cliente'],
                // Las barras no dibujan negativos: un margen bajo cero se queda
                // sin barra y en rojo, que es exactamente lo que hay que ver.
                'valor'    => max(0.0, $margen),
                'texto'    => $texto,
                'alerta'   => $margen <= 0 || $f['sin_margen'] > 0,
            ];
        }

        usort($filas, fn ($x, $y) => $y['valor'] <=> $x['valor']);

        return self::rotulo('Margen por cliente') . Graficos::barras($filas, '#638E76');
    }

    private static function rotulo(string $texto): string
    {
        return '<p style="margin:14px 0 2px;font-size:12px;font-weight:700;text-transform:uppercase;'
            . 'letter-spacing:.04em;color:#6b7280;">' . e($texto) . '</p>';
    }

    /**
     * La nota que el account manager escribió para ESTA semana.
     *
     * Si la fecha guardada no es la del lunes que se está informando, se ignora:
     * una nota de hace tres semanas saliendo cada lunes como si fuera nueva es
     * peor que no tener nota.
     */
    private static function nota(Cliente $cliente, Carbon $desde): string
    {
        $texto = trim((string) $cliente->comentario_semanal);

        if ($texto === '' || $cliente->comentario_semanal_semana?->toDateString() !== $desde->toDateString()) {
            return '';
        }

        return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:8px 0 18px;">'
            . '<tr><td style="padding:14px 16px;background:#f6f4f2;border-radius:8px;font-size:14px;'
            . 'line-height:1.6;color:#374151;">' . nl2br(e($texto)) . '</td></tr></table>';
    }

    // -------------------------------------------------------------- series

    /**
     * Inversión y leads de las últimas semanas, para el cliente.
     *
     * El coste se pide al motor una vez por línea y mes de toda la ventana, no
     * una vez por semana: `costeClienteMes()` consulta la base de datos en cada
     * llamada, y pedirlo semana a semana multiplicaba las consultas por seis.
     *
     * @param  Collection<int,\App\Models\Linea>  $lineas
     * @return list<array{etiqueta:string,coste:float,leads:float,cpl:float}>
     */
    private function serieSemanal(Collection $lineas, Carbon $hasta, int $semanas): array
    {
        if ($lineas->isEmpty()) {
            return [];
        }

        $fin = $hasta->copy();
        $ini = $fin->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($semanas - 1);

        $costePorDia = [];
        $cursor      = $ini->copy()->startOfMonth();

        while ($cursor->lte($fin)) {
            foreach ($lineas as $linea) {
                foreach ($this->engine->costeClienteMes($linea, $cursor->year, $cursor->month) as $f => $c) {
                    $costePorDia[$f] = ($costePorDia[$f] ?? 0.0) + $c;
                }
            }
            $cursor->addMonth();
        }

        $leadsPorDia = MetricaDiaria::whereIn('linea_id', $lineas->pluck('id'))
            ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
            ->selectRaw('fecha, SUM(leads) leads')
            ->groupBy('fecha')
            ->get()
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->fecha)->toDateString() => (float) $r->leads])
            ->all();

        return self::porSemanas($ini, $semanas, $costePorDia, $leadsPorDia);
    }

    /**
     * Gasto real y leads por semana, para el informe interno. Una sola consulta:
     * aquí no hace falta el motor de precios.
     *
     * Se le pasan las líneas que cubre el informe (las de clientes activos) y no
     * se consulta la tabla entera: si no, la barra de esta semana no cuadraría
     * con el total de la tabla de arriba y parecería que una de las dos miente.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $lineaIds
     * @return list<array{etiqueta:string,coste:float,leads:float,cpl:float}>
     */
    private function serieGasto(Carbon $hasta, int $semanas, Collection $lineaIds): array
    {
        $fin = $hasta->copy();
        $ini = $fin->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($semanas - 1);

        if ($lineaIds->isEmpty()) {
            return [];
        }

        $gasto = [];
        $leads = [];

        MetricaDiaria::whereIn('linea_id', $lineaIds)
            ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
            ->selectRaw('fecha, SUM(gasto_real) gasto, SUM(leads) leads')
            ->groupBy('fecha')
            ->get()
            ->each(function ($r) use (&$gasto, &$leads) {
                $f         = Carbon::parse($r->fecha)->toDateString();
                $gasto[$f] = (float) $r->gasto;
                $leads[$f] = (float) $r->leads;
            });

        return self::porSemanas($ini, $semanas, $gasto, $leads);
    }

    /**
     * Reparte dos mapas día => importe en cubos semanales de lunes a domingo.
     *
     * @param  array<string,float>  $coste
     * @param  array<string,float>  $leads
     * @return list<array{etiqueta:string,coste:float,leads:float,cpl:float}>
     */
    private static function porSemanas(Carbon $primerLunes, int $semanas, array $coste, array $leads): array
    {
        $out = [];

        for ($s = 0; $s < $semanas; $s++) {
            $lunes = $primerLunes->copy()->addWeeks($s);
            $c     = 0.0;
            $l     = 0.0;

            for ($d = 0; $d < 7; $d++) {
                $f  = $lunes->copy()->addDays($d)->toDateString();
                $c += $coste[$f] ?? 0.0;
                $l += $leads[$f] ?? 0.0;
            }

            $out[] = [
                'etiqueta' => $lunes->format('d/m'),
                'coste'    => round($c, 2),
                'leads'    => $l,
                'cpl'      => $l > 0 ? round($c / $l, 2) : 0.0,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- HTML

    private function tablaKpisCliente(array $a, array $b): string
    {
        $filas = [
            ['Inversión', self::euros($a['coste']), self::variacion($a['coste'], $b['coste'])],
            ['Impresiones', self::entero($a['impresiones']), self::variacion($a['impresiones'], $b['impresiones'])],
            ['Clics', self::entero($a['clics']), self::variacion($a['clics'], $b['clics'])],
            ['CTR', self::numero($a['ctr']) . ' %', ''],
            ['CPC', self::euros($a['cpc']), ''],
            ['Leads', self::numero($a['leads']), self::variacion($a['leads'], $b['leads'])],
            ['CPL', self::euros($a['cpl']), ''],
        ];

        return self::tabla(['Métrica', 'Semana', 'vs. semana anterior'], $filas);
    }

    private function tablaCanales(Collection $canales): string
    {
        if ($canales->isEmpty()) {
            return '<p>Sin actividad en esta semana.</p>';
        }

        return self::tabla(
            ['Canal', 'Inversión', 'Clics', 'Leads', 'CPL'],
            $canales->map(fn ($k) => [
                $k['canal'], self::euros($k['coste']), self::entero($k['clics']),
                self::numero($k['leads']), self::euros($k['cpl']),
            ])->all(),
        );
    }

    private function tablaKpisAdmin(array $t): string
    {
        return self::tabla(['Gasto real', 'Facturado', 'Margen', 'Clics', 'Leads'], [[
            self::euros($t['gasto']), self::euros($t['facturado']),
            self::euros($t['facturado'] - $t['gasto']),
            self::entero($t['clics']), self::numero($t['leads']),
        ]]);
    }

    private function tablaCuentas(array $filas): string
    {
        if (! $filas) {
            return '<p>Ninguna cuenta tuvo actividad esta semana.</p>';
        }

        return self::tabla(
            ['Cliente', 'Cuenta', 'Canal', 'Gasto real', 'Facturado', 'Margen', 'Sin margen', 'Clics', 'Leads'],
            array_map(fn ($f) => [
                $f['cliente'], $f['cuenta'], $f['plataforma'],
                self::euros($f['gasto']), self::euros($f['facturado']),
                self::euros($f['facturado'] - $f['gasto']),
                $f['sin_margen'] > 0 ? self::euros($f['sin_margen']) : '—',
                self::entero($f['clics']), self::numero($f['leads']),
            ], $filas),
        );
    }

    /** Tabla con estilos en línea: los clientes de correo ignoran las hojas de estilo. */
    private static function tabla(array $cabecera, array $filas): string
    {
        $th = 'style="text-align:left;padding:8px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;font-size:13px;"';
        $td = 'style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:14px;"';

        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:8px 0 16px;"><thead><tr>';
        foreach ($cabecera as $c) {
            $html .= '<th ' . $th . '>' . e($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($filas as $fila) {
            $html .= '<tr>';
            foreach ($fila as $v) {
                $html .= '<td ' . $td . '>' . e($v) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------ formatos

    public static function periodo(Carbon $desde, Carbon $hasta): string
    {
        return $desde->format('d/m') . ' al ' . $hasta->format('d/m/Y');
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }

    private static function entero(int|float $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    private static function numero(float $v): string
    {
        return number_format($v, $v == floor($v) ? 0 : 2, ',', '.');
    }

    private static function variacion(float $actual, float $anterior): string
    {
        if ($anterior == 0) {
            return $actual > 0 ? 'nuevo' : '—';
        }
        $pct = ($actual - $anterior) / $anterior * 100;

        return ($pct >= 0 ? '+' : '') . number_format($pct, 0, ',', '.') . ' %';
    }
}

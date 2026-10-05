<?php

namespace App\Services\Informes;

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
            ->map(fn (Collection $ls, string $plat) => ['canal' => Cuenta::PLATAFORMAS[$plat] ?? $plat]
                + $this->engine->kpis($ls, $desde, $hasta))
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
            'tabla_kpis'    => $this->tablaKpisCliente($actual, $anterior),
            'tabla_canales' => $this->tablaCanales($canales),
        ];

        $render = PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL)->render($texto, $html);

        return $render + [
            'sin_actividad' => $actual['coste'] == 0 && $actual['clics'] == 0 && $actual['impresiones'] == 0,
        ];
    }

    /**
     * Resumen interno de todas las cuentas. Aquí SÍ va el gasto real y el
     * margen: es para el account manager, nunca para un cliente.
     *
     * @return array{asunto:string,cuerpo:string}
     */
    public function paraAdmin(Carbon $desde, Carbon $hasta): array
    {
        $cuentas = Cuenta::with(['cliente', 'conexionApi', 'lineas.presupuestoEventos'])
            ->whereHas('cliente', fn ($q) => $q->where('activo', true))
            ->get()
            ->sortBy(fn (Cuenta $c) => $c->cliente->nombre . ' ' . $c->plataforma);

        $reales = MetricaDiaria::whereIn('cuenta_id', $cuentas->pluck('id'))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('linea_id, SUM(gasto_real) gasto, SUM(clics) clics, SUM(leads) leads')
            ->groupBy('linea_id')
            ->get()
            ->keyBy('linea_id');

        $filas    = [];
        $sinTramo = [];
        $total    = ['gasto' => 0.0, 'facturado' => 0.0, 'clics' => 0, 'leads' => 0.0];

        foreach ($cuentas as $cuenta) {
            $fila = ['gasto' => 0.0, 'facturado' => 0.0, 'clics' => 0, 'leads' => 0.0, 'sin_facturar' => 0.0];

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

                // Gasto que no se está facturando: falta tramo o está pausada.
                if ($gasto > 0 && $facturado == 0) {
                    $fila['sin_facturar'] += $gasto;
                    $sinTramo[] = $cuenta->cliente->nombre . ' · ' . ($linea->producto ?: $linea->nombre)
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
        foreach ($sinTramo as $s) {
            $avisos[] = 'Gasto sin tramo de facturación: ' . $s;
        }
        foreach (Cliente::where('activo', true)->where('informe_semanal', true)->get() as $c) {
            if (! $c->emailsInforme()) {
                $avisos[] = 'El cliente ' . $c->nombre . ' tiene el informe semanal activado pero ningún email válido.';
            }
        }

        $texto = [
            'periodo'   => self::periodo($desde, $hasta),
            'desde'     => $desde->format('d/m/Y'),
            'hasta'     => $hasta->format('d/m/Y'),
            'gasto'     => self::euros($total['gasto']),
            'facturado' => self::euros($total['facturado']),
            'margen'    => self::euros($total['facturado'] - $total['gasto']),
        ];

        $html = [
            'tabla_kpis'    => $this->tablaKpisAdmin($total),
            'tabla_cuentas' => $this->tablaCuentas($filas),
            'avisos'        => $avisos
                ? '<ul>' . implode('', array_map(fn ($a) => '<li>' . e($a) . '</li>', $avisos)) . '</ul>'
                : '<p>Sin avisos esta semana.</p>',
        ];

        return PlantillaEmail::deClave(PlantillaEmail::INFORME_ADMIN)->render($texto, $html);
    }

    /**
     * Destinatarios del resumen interno: INFORME_ADMIN_EMAIL en .env si existe
     * (uno o varios, separados por coma); si no, todos los usuarios admin.
     *
     * @return list<string>
     */
    public static function destinatariosAdmin(): array
    {
        $config = Cliente::separarEmails(config('services.informes.admin'));

        return $config ?: User::where('es_admin', true)->pluck('email')
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
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
            ['Cliente', 'Cuenta', 'Canal', 'Gasto real', 'Facturado', 'Margen', 'Sin facturar', 'Clics', 'Leads'],
            array_map(fn ($f) => [
                $f['cliente'], $f['cuenta'], $f['plataforma'],
                self::euros($f['gasto']), self::euros($f['facturado']),
                self::euros($f['facturado'] - $f['gasto']),
                $f['sin_facturar'] > 0 ? self::euros($f['sin_facturar']) : '—',
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

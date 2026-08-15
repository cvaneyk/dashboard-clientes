<?php

namespace App\Http\Controllers;

use App\Models\MetricaDiaria;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dashboard del cliente. Solo ve los datos de SU cliente (scope por cliente_id).
 */
class DashboardController extends Controller
{
    public function index(Request $request, PricingEngine $engine): View
    {
        $user    = Auth::user();
        $cliente = $user->cliente;

        // Mes a mostrar (?mes=YYYY-MM). Por defecto, el último mes con datos.
        $mesActual = $this->resolverMes($request->query('mes'), $cliente->id);
        $anio      = (int) $mesActual->year;
        $mes       = (int) $mesActual->month;
        $desde     = $mesActual->copy()->startOfMonth();
        $hasta     = $mesActual->copy()->endOfMonth();

        $lineas = $cliente->lineas()->where('activo', true)->orderBy('nombre')->get();

        // KPIs globales del cliente.
        $kpis = $engine->kpis($lineas, $desde, $hasta);

        // Serie diaria combinada (suma de todas las líneas).
        $dias   = $desde->daysInMonth;
        $serie  = [];
        for ($d = 1; $d <= $dias; $d++) {
            $fecha = $desde->copy()->day($d)->toDateString();
            $serie[$fecha] = ['coste' => 0.0, 'clics' => 0, 'leads' => 0.0];
        }
        foreach ($lineas as $linea) {
            foreach ($engine->serieDiaria($linea, $anio, $mes) as $fecha => $row) {
                if (! isset($serie[$fecha])) {
                    continue;
                }
                $serie[$fecha]['coste'] += $row['coste'];
                $serie[$fecha]['clics'] += $row['clics'];
                $serie[$fecha]['leads'] += $row['leads'];
            }
        }

        // Desglose por línea.
        $porLinea = $lineas->map(function ($linea) use ($engine, $desde, $hasta) {
            $coste = $engine->costeClientePeriodo($linea, $desde, $hasta);
            $r = MetricaDiaria::where('linea_id', $linea->id)
                ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
                ->selectRaw('SUM(clics) clics, SUM(leads) leads, SUM(impresiones) imp')
                ->first();
            $clics = (int) ($r->clics ?? 0);
            $leads = (float) ($r->leads ?? 0);
            return [
                'nombre'  => $linea->producto ?: $linea->nombre,
                'coste'   => $coste,
                'clics'   => $clics,
                'leads'   => $leads,
                'cpc'     => $clics > 0 ? round($coste / $clics, 2) : 0.0,
                'cpl'     => $leads > 0 ? round($coste / $leads, 2) : 0.0,
            ];
        });

        // Meses disponibles para el selector.
        $meses = MetricaDiaria::where('cliente_id', $cliente->id)
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') ym")
            ->distinct()->orderByDesc('ym')->pluck('ym');

        return view('portal.dashboard', [
            'cliente'   => $cliente,
            'kpis'      => $kpis,
            'serie'     => $serie,
            'porLinea'  => $porLinea,
            'mesActual' => $mesActual,
            'meses'     => $meses,
        ]);
    }

    protected function resolverMes(?string $mesParam, int $clienteId): Carbon
    {
        if ($mesParam && preg_match('/^\d{4}-\d{2}$/', $mesParam)) {
            return Carbon::createFromFormat('Y-m', $mesParam)->startOfMonth();
        }

        $ultima = MetricaDiaria::where('cliente_id', $clienteId)->max('fecha');

        return $ultima ? Carbon::parse($ultima)->startOfMonth() : Carbon::now()->startOfMonth();
    }
}

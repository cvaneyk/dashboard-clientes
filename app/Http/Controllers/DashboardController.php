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
 * Muestra: consolidado del mes, desglose por canal y por campaña.
 */
class DashboardController extends Controller
{
    private const CANALES = [
        'meta'     => 'Meta Ads',
        'google'   => 'Google Ads',
        'tiktok'   => 'TikTok Ads',
        'linkedin' => 'LinkedIn Ads',
    ];

    public function index(Request $request, PricingEngine $engine): View
    {
        $user    = Auth::user();
        $cliente = $user->cliente;

        $mesActual = $this->resolverMes($request->query('mes'), $cliente->id);
        $anio      = (int) $mesActual->year;
        $mes       = (int) $mesActual->month;
        $desde     = $mesActual->copy()->startOfMonth();
        $hasta     = $mesActual->copy()->endOfMonth();

        $lineas = $cliente->lineas()->where('activo', true)->orderBy('nombre')->get();

        // KPIs consolidados (todos los canales).
        $kpis = $engine->kpis($lineas, $desde, $hasta);

        // Serie diaria consolidada.
        $dias  = $desde->daysInMonth;
        $serie = [];
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

        // Coste-cliente por línea (para reutilizar en canal y campaña).
        $costeLinea = [];
        foreach ($lineas as $l) {
            $costeLinea[$l->id] = $engine->costeClientePeriodo($l, $desde, $hasta);
        }

        // Métricas reales por línea en el mes.
        $reales = MetricaDiaria::whereIn('linea_id', $lineas->pluck('id'))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('linea_id, SUM(clics) clics, SUM(leads) leads, SUM(impresiones) imp')
            ->groupBy('linea_id')
            ->get()
            ->keyBy('linea_id');

        // Desglose POR CANAL.
        $porCanal = $lineas->groupBy('plataforma')->map(function ($lins, $plat) use ($costeLinea, $reales) {
            $coste = $clics = $leads = $imp = 0;
            foreach ($lins as $l) {
                $coste += $costeLinea[$l->id] ?? 0;
                $r = $reales->get($l->id);
                $clics += (int) ($r->clics ?? 0);
                $leads += (float) ($r->leads ?? 0);
                $imp   += (int) ($r->imp ?? 0);
            }
            return [
                'plataforma' => $plat,
                'nombre'     => self::CANALES[$plat] ?? ucfirst($plat),
                'coste'      => round($coste, 2),
                'clics'      => $clics,
                'leads'      => $leads,
                'imp'        => $imp,
                'cpc'        => $clics > 0 ? round($coste / $clics, 2) : 0.0,
                'cpl'        => $leads > 0 ? round($coste / $leads, 2) : 0.0,
            ];
        })->sortByDesc('coste')->values();

        // Desglose POR CAMPAÑA (con su canal).
        $porLinea = $lineas->map(function ($l) use ($costeLinea, $reales) {
            $coste = $costeLinea[$l->id] ?? 0;
            $r     = $reales->get($l->id);
            $clics = (int) ($r->clics ?? 0);
            $leads = (float) ($r->leads ?? 0);
            return [
                'nombre'     => $l->producto ?: $l->nombre,
                'plataforma' => self::CANALES[$l->plataforma] ?? ucfirst($l->plataforma),
                'coste'      => round($coste, 2),
                'clics'      => $clics,
                'leads'      => $leads,
                'cpc'        => $clics > 0 ? round($coste / $clics, 2) : 0.0,
                'cpl'        => $leads > 0 ? round($coste / $leads, 2) : 0.0,
            ];
        })->sortByDesc('coste')->values();

        $meses = MetricaDiaria::where('cliente_id', $cliente->id)
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') ym")
            ->distinct()->orderByDesc('ym')->pluck('ym');

        return view('portal.dashboard', [
            'cliente'   => $cliente,
            'kpis'      => $kpis,
            'serie'     => $serie,
            'porCanal'  => $porCanal,
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

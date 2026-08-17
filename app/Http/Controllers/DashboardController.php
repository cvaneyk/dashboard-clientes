<?php

namespace App\Http\Controllers;

use App\Models\MetricaDiaria;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dashboard del cliente. Exporta TODO el dataset diario (por campaña y canal)
 * al navegador; el filtrado, comparación, orden, búsqueda y gráfico se resuelven
 * en el cliente (JS) sin recargar. Scope estricto por cliente_id.
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

        $lineas = $cliente->lineas()->where('activo', true)->get();

        // Ventana temporal = rango de fechas con datos del cliente.
        $min = MetricaDiaria::where('cliente_id', $cliente->id)->min('fecha');
        $max = MetricaDiaria::where('cliente_id', $cliente->id)->max('fecha');
        if (! $min || ! $max) {
            $min = Carbon::now()->startOfMonth()->toDateString();
            $max = Carbon::now()->toDateString();
        }
        $ini = Carbon::parse($min)->startOfDay();
        $fin = Carbon::parse($max)->startOfDay();

        // Métricas reales por línea y día.
        $metricas = MetricaDiaria::whereIn('linea_id', $lineas->pluck('id'))
            ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
            ->get(['linea_id', 'fecha', 'clics', 'leads', 'impresiones'])
            ->groupBy('linea_id');

        // Coste-cliente diario por línea (recorriendo los meses de la ventana).
        $meses = [];
        $cursor = $ini->copy()->startOfMonth();
        while ($cursor->lte($fin)) {
            $meses[] = [$cursor->year, $cursor->month];
            $cursor->addMonth();
        }

        $rows = [];
        foreach ($lineas as $linea) {
            $nombre = $linea->producto ?: $linea->nombre;
            $plat   = $linea->plataforma;

            // coste por fecha
            $coste = [];
            foreach ($meses as [$y, $m]) {
                foreach ($engine->costeClienteMes($linea, $y, $m) as $f => $c) {
                    $coste[$f] = $c;
                }
            }

            // métricas por fecha
            $mets = [];
            foreach (($metricas[$linea->id] ?? collect()) as $r) {
                $f = Carbon::parse($r->fecha)->toDateString();
                $mets[$f] = [
                    'k' => (int) $r->clics,
                    'l' => (float) $r->leads,
                    'i' => (int) $r->impresiones,
                ];
            }

            // union de fechas dentro de la ventana
            $fechas = array_unique(array_merge(array_keys($coste), array_keys($mets)));
            foreach ($fechas as $f) {
                if ($f < $ini->toDateString() || $f > $fin->toDateString()) {
                    continue;
                }
                $c = round($coste[$f] ?? 0, 2);
                $k = $mets[$f]['k'] ?? 0;
                $l = $mets[$f]['l'] ?? 0;
                $i = $mets[$f]['i'] ?? 0;
                if ($c == 0 && $k == 0 && $l == 0 && $i == 0) {
                    continue;
                }
                $rows[] = [
                    'f'    => $f,
                    'p'    => $plat,
                    'camp' => $nombre,
                    'c'    => $c,
                    'k'    => $k,
                    'l'    => $l,
                    'i'    => $i,
                ];
            }
        }

        $canales = array_values(array_unique(array_map(fn ($r) => $r['p'], $rows)));

        // Logo: si es una ruta guardada (no URL), la resolvemos al disco público.
        $logoUrl = $cliente->logo;
        if ($logoUrl && ! str_starts_with($logoUrl, 'http')) {
            $logoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($logoUrl);
        }

        $data = [
            'rows'     => $rows,
            'canales'  => $canales,
            'nombres'  => self::CANALES,
            'min'      => $ini->toDateString(),
            'max'      => $fin->toDateString(),
            'cliente'  => $cliente->nombre,
            'logo'     => $logoUrl,
        ];

        return view('portal.dashboard', ['cliente' => $cliente, 'data' => $data, 'logoUrl' => $logoUrl]);
    }
}

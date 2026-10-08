<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\Override;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Gastos globales: el gasto de TODAS las líneas sumado, para verlo unificado
 * por canal o por cliente.
 *
 * Igual que el dashboard, vuelca el dataset al navegador y el JS agrupa,
 * filtra y compara sin recargar. La diferencia es el alcance: aquí entran a la
 * vez todos los clientes de User::clientesVisibles() (un usuario de cliente,
 * el suyo; un admin, todos), y por eso el detalle por campaña no viaja: las
 * filas ya llegan sumadas por día, cliente y canal.
 *
 * Dos vistas, como el dashboard:
 *   - la del cliente, solo con lo facturado (sale del motor de precios);
 *   - la interna (solo admins), con gasto real, facturado y margen juntos.
 */
class GlobalesController extends Controller
{
    /**
     * Vista interna. Es, con el dashboard interno, la única pantalla del
     * portal que enseña gasto_real: la puerta se cierra aquí además de en la
     * ruta, para que un cliente no llegue ni conociendo la URL.
     */
    public function interno(Request $request, PricingEngine $engine): View|RedirectResponse
    {
        abort_unless(Auth::user()?->es_admin, 403);

        return $this->index($request, $engine, interno: true);
    }

    public function index(Request $request, PricingEngine $engine, bool $interno = false): View|RedirectResponse
    {
        $user     = Auth::user();
        $visibles = $user->clientesVisibles();
        $interno  = $interno && (bool) $user->es_admin;

        if ($visibles->isEmpty()) {
            return $user->es_admin
                ? redirect('/admin')
                : redirect()->route('portal.login');
        }

        // Mismo criterio que el dashboard (líneas activas), para que el total
        // de un cliente aquí cuadre con el que ve en su dashboard.
        $lineas = Linea::whereIn('cliente_id', $visibles->pluck('id'))
            ->where('activo', true)
            ->with('presupuestoEventos')
            ->get();

        $metricas = MetricaDiaria::whereIn('linea_id', $lineas->pluck('id'))
            ->get(['linea_id', 'fecha', 'clics', 'leads', 'impresiones', 'gasto_real'])
            ->groupBy('linea_id');

        // Meses con ajuste manual por día: pueden facturar aunque no haya gasto.
        $mesesOverride = Override::whereIn('linea_id', $lineas->pluck('id'))
            ->where('campo', 'coste_cliente')
            ->whereNotNull('fecha')
            ->get(['linea_id', 'fecha'])
            ->groupBy('linea_id')
            ->map(fn ($ovs) => $ovs->map(fn ($o) => Carbon::parse($o->fecha)->format('Y-m'))->all());

        $slugs = $visibles->pluck('slug', 'id');
        $acc   = [];

        foreach ($lineas as $linea) {
            $mets = [];
            foreach (($metricas[$linea->id] ?? collect()) as $r) {
                $mets[Carbon::parse($r->fecha)->toDateString()] = $r;
            }

            // Lo facturado sale del motor, como en el dashboard, para que no
            // haya dos cálculos que puedan discrepar. Solo se le piden los meses
            // con gasto o con ajuste manual: en el resto todo día sale a 0 €
            // (sin gasto no hay nada que marginar), y recorrer la ventana entera
            // de todos los clientes línea a línea no escalaría.
            $meses = array_unique(array_merge(
                array_map(fn ($f) => substr($f, 0, 7), array_keys($mets)),
                $mesesOverride[$linea->id] ?? [],
            ));

            $coste = [];
            foreach ($meses as $ym) {
                [$y, $m] = array_map('intval', explode('-', $ym));
                foreach ($engine->costeClienteMes($linea, $y, $m) as $f => $c) {
                    $coste[$f] = $c;
                }
            }

            $fechas = array_unique(array_merge(array_keys($coste), array_keys($mets)));
            foreach ($fechas as $f) {
                $r = $mets[$f] ?? null;
                $c = (float) ($coste[$f] ?? 0);
                $g = (float) ($r->gasto_real ?? 0);
                $k = (int) ($r->clics ?? 0);
                $l = (float) ($r->leads ?? 0);
                $i = (int) ($r->impresiones ?? 0);
                if ($c == 0 && $g == 0 && $k == 0 && $l == 0 && $i == 0) {
                    continue;
                }

                $clave = $f . '|' . $linea->cliente_id . '|' . $linea->plataforma;
                $o = $acc[$clave] ??= [
                    'f'  => $f,
                    'cl' => $slugs[$linea->cliente_id],
                    'p'  => $linea->plataforma,
                    'c'  => 0.0, 'g' => 0.0, 'k' => 0, 'l' => 0.0, 'i' => 0,
                ];
                $o['c'] += $c;
                $o['g'] += $g;
                $o['k'] += $k;
                $o['l'] += $l;
                $o['i'] += $i;
                $acc[$clave] = $o;
            }
        }

        // gasto_real solo sale de aquí en la vista interna. En la del cliente
        // se quita la clave entera, no se deja a cero: que ni exista.
        $rows = [];
        foreach ($acc as $o) {
            $o['c'] = round($o['c'], 2);
            $o['l'] = round($o['l'], 2);
            if ($interno) {
                $o['g'] = round($o['g'], 2);
            } else {
                unset($o['g']);
            }
            $rows[] = $o;
        }
        usort($rows, fn ($a, $b) => strcmp($a['f'], $b['f']));

        $min = $rows[0]['f'] ?? Carbon::now()->startOfMonth()->toDateString();
        $max = $rows ? end($rows)['f'] : Carbon::now()->toDateString();

        $data = [
            'rows'     => $rows,
            'canales'  => array_values(array_unique(array_column($rows, 'p'))),
            'clientes' => $visibles->mapWithKeys(fn ($c) => [
                $c->slug => ['n' => $c->nombre, 'color' => $c->color],
            ]),
            'nombres'  => Cuenta::PLATAFORMAS,
            'min'      => $min,
            'max'      => $max,
        ];

        return view('portal.globales', [
            'data'      => $data,
            'visibles'  => $visibles,
            'esAdmin'   => (bool) $user->es_admin,
            'esInterno' => $interno,
            // Columnas de importe. Se mandan desde aquí, y no escritas en el JS,
            // porque el JavaScript viaja entero al navegador: un literal "gasto
            // real" en él le contaría al cliente que existe la vista interna.
            'importes'  => $interno
                ? [
                    ['g', 'Gasto real', 'eur'],
                    ['c', 'Facturado', 'eur'],
                    ['m', 'Margen', 'eur'],
                    ['mp', 'Margen % s/ gasto', 'pct'],
                ]
                : [
                    ['c', 'Inversión', 'eur'],
                ],
        ]);
    }
}

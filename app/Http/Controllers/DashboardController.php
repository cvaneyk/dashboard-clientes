<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MetricaDiaria;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dashboard del cliente. Exporta TODO el dataset diario (por campaña y canal)
 * al navegador; el filtrado, comparación, orden, búsqueda y gráfico se resuelven
 * en el cliente (JS) sin recargar.
 *
 * SCOPE: un usuario de cliente ve solo el suyo; un admin puede cambiar de
 * cliente con el selector de la cabecera. El cliente a pintar se resuelve
 * SIEMPRE contra User::clientesVisibles(), nunca directamente desde la URL:
 * es el punto donde un portal multicliente se convierte en una fuga de datos.
 * Por eso tampoco se manda a la página el dataset de varios clientes a la vez,
 * cambiar de cliente recarga.
 */
class DashboardController extends Controller
{
    private const CANALES = [
        'meta'     => 'Meta Ads',
        'google'   => 'Google Ads',
        'tiktok'   => 'TikTok Ads',
        'linkedin' => 'LinkedIn Ads',
    ];

    /**
     * Vista interna: la misma pantalla, pero con el GASTO REAL de plataforma
     * en lugar del coste facturado al cliente. Sirve para ver el margen.
     *
     * Solo admins. Es la única vista del portal que enseña gasto_real, así que
     * la puerta se cierra aquí de forma explícita además de por la ruta: un
     * usuario de cliente no puede llegar ni aunque conozca la URL.
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

        // Cinturón y tirantes: aunque index() es pública, el modo interno solo
        // se activa desde interno(), que ya ha comprobado el permiso.
        $interno = $interno && (bool) $user->es_admin;

        if ($visibles->isEmpty()) {
            // Admin sin ningún cliente dado de alta, o usuario sin cliente.
            return $user->es_admin
                ? redirect('/admin')
                : redirect()->route('portal.login');
        }

        $cliente = $this->clienteSeleccionado($request, $visibles);

        // Con los presupuestos cargados: los necesitan los medidores de objetivo
        // y sin esto sería una consulta por línea.
        $lineas = $cliente->lineas()->where('activo', true)->with('presupuestoEventos')->get();

        // Ventana temporal = rango de fechas con datos del cliente.
        $min = MetricaDiaria::where('cliente_id', $cliente->id)->min('fecha');
        $max = MetricaDiaria::where('cliente_id', $cliente->id)->max('fecha');
        if (! $min || ! $max) {
            $min = Carbon::now()->startOfMonth()->toDateString();
            $max = Carbon::now()->toDateString();
        }
        $ini = Carbon::parse($min)->startOfDay();
        $fin = Carbon::parse($max)->startOfDay();

        // Métricas reales por línea y día. gasto_real solo se lee en la vista
        // interna: en la del cliente ni siquiera sale de la base de datos.
        $columnas = ['linea_id', 'fecha', 'clics', 'leads', 'impresiones'];
        if ($interno) {
            $columnas[] = 'gasto_real';
        }

        $metricas = MetricaDiaria::whereIn('linea_id', $lineas->pluck('id'))
            ->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
            ->get($columnas)
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

            // Coste por fecha. En la vista del cliente es el objetivo prorrateado
            // que calcula el motor; en la interna, el gasto real de plataforma.
            // Va en la MISMA clave a propósito: así toda la página (gráfico,
            // tabla, KPIs, CSV) funciona igual sin duplicar lógica.
            $coste = [];
            if (! $interno) {
                foreach ($meses as [$y, $m]) {
                    foreach ($engine->costeClienteMes($linea, $y, $m) as $f => $c) {
                        $coste[$f] = $c;
                    }
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

                if ($interno) {
                    $coste[$f] = round((float) $r->gasto_real, 2);
                }
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

        // Los medidores de consumo quedaron sin sentido al pasar la facturación
        // a porcentaje sobre el gasto: ya no hay objetivo mensual contra el que
        // medir. Se manda vacío para que la sección no se pinte; el bloque se
        // conserva por si se reaprovecha contra el tope de plataforma en la
        // vista interna, donde sí está permitido enseñar datos de plataforma.
        $objetivos = [];

        // Logo: si es una URL completa, se usa tal cual; si es un archivo subido,
        // se sirve por nuestra ruta propia (no depende de storage:link).
        $logoUrl = null;
        if ($cliente->logo) {
            $logoUrl = str_starts_with($cliente->logo, 'http')
                ? $cliente->logo
                : route('cliente.logo', $cliente);
        }

        $data = [
            'rows'      => $rows,
            'canales'   => $canales,
            'objetivos' => $objetivos,
            'nombres'   => self::CANALES,
            'min'      => $ini->toDateString(),
            'max'      => $fin->toDateString(),
            'cliente'  => $cliente->nombre,
            'logo'     => $logoUrl,
        ];

        return view('portal.dashboard', [
            'cliente'       => $cliente,
            'data'          => $data,
            'logoUrl'       => $logoUrl,
            'visibles'      => $visibles,
            'esAdmin'       => (bool) $user->es_admin,
            'esInterno'     => $interno,
            // Que el número diga lo que es: confundir las dos cifras en una
            // captura de pantalla es justo el riesgo de esta vista.
            //
            // Estos textos SE PASAN desde aquí en vez de escribirse en el JS:
            // el JavaScript es el mismo para las dos vistas y viaja entero al
            // navegador, así que un literal "gasto real" dentro le contaría al
            // cliente que existe una vista interna.
            'etiquetaCoste'    => $interno ? 'Gasto real' : 'Inversión',
            'tituloObjetivos'  => $interno ? 'Consumo sobre objetivo' : 'Objetivo del mes',
        ]);
    }

    /**
     * Qué cliente se pinta. El slug llega por la URL (selector) o de la última
     * elección guardada en sesión, y en ambos casos se busca DENTRO de los
     * clientes permitidos: un slug que no esté en esa lista se ignora y se cae
     * al primero, sin filtrar siquiera si ese cliente existe o no.
     *
     * @param  Collection<int,Cliente>  $visibles
     */
    private function clienteSeleccionado(Request $request, Collection $visibles): Cliente
    {
        $pedido = $request->query('cliente') ?? $request->session()->get('portal.cliente');

        $cliente = is_string($pedido) ? $visibles->firstWhere('slug', $pedido) : null;
        $cliente ??= $visibles->first();

        $request->session()->put('portal.cliente', $cliente->slug);

        return $cliente;
    }
}

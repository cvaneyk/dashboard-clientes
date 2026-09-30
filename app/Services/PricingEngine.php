<?php

namespace App\Services;

use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\Override;
use App\Models\PresupuestoEvento;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Motor de precios del portal de clientes.
 *
 * REGLA DE NEGOCIO
 * ----------------
 * Al cliente se le factura el gasto real de la plataforma más un porcentaje:
 *
 *     coste_cliente_dia = gasto_real_dia * (1 + porcentaje/100)
 *
 * El porcentaje vive en tramos con fecha de inicio y fin (presupuesto_eventos).
 * De ahí salen los casos que hay que soportar:
 *   - Cambiar el margen a mitad de mes: se abre un tramo nuevo.
 *   - Pausar una línea: tramo en estado 'pausada' → 0 €/día.
 *   - Día fuera de todo tramo → 0 €: no hay con qué facturarlo.
 *   - Día sin gasto en la plataforma → 0 €: no hay nada que marginar.
 *
 * OJO: el coste del cliente sigue al gasto real día a día, así que ya NO hay
 * objetivo mensual ni prorrateo. Cambiar el porcentaje de un tramo recalcula
 * todo el periodo que cubre, porque el coste no se persiste.
 *
 * El gasto real por sí solo sigue siendo interno: lo que se enseña al cliente
 * es siempre el resultado de esta multiplicación, nunca la base.
 */
class PricingEngine
{
    /**
     * Coste-cliente por día de una línea durante un mes concreto.
     *
     * @return Collection<string,float>  clave 'Y-m-d' => coste del día
     */
    public function costeClienteMes(Linea $linea, int $anio, int $mes): Collection
    {
        $inicioMes = CarbonImmutable::create($anio, $mes, 1)->startOfDay();
        $diasMes   = $inicioMes->daysInMonth;

        $tramos = $linea->presupuestoEventos()->get();
        $gastos = $this->gastoRealPorDia($linea, $anio, $mes);

        $out = collect();

        for ($d = 1; $d <= $diasMes; $d++) {
            $dia   = $inicioMes->addDays($d - 1);
            $clave = $dia->toDateString();

            // Qué tramo manda si varios cubren el día: ver PresupuestoEvento::vigenteEn().
            $tramo = PresupuestoEvento::vigenteEn($tramos, $dia);

            // Día fuera de todo tramo, o línea pausada: no se factura.
            if (! $tramo || $tramo->estado === 'pausada') {
                $out->put($clave, 0.0);
                continue;
            }

            // Se factura lo que se pagó de verdad a la plataforma más el margen.
            // Si ese día no hubo gasto, no hay nada que facturar.
            $out->put($clave, round(($gastos[$clave] ?? 0.0) * $tramo->multiplicador(), 2));
        }

        return $this->aplicarOverrides($linea, $out);
    }

    /**
     * Gasto real de la plataforma por día, indexado por 'Y-m-d'.
     * Se lee de una vez por mes: el coste de cada día depende de él.
     *
     * @return array<string,float>
     */
    private function gastoRealPorDia(Linea $linea, int $anio, int $mes): array
    {
        return \App\Models\MetricaDiaria::where('linea_id', $linea->id)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->get(['fecha', 'gasto_real'])
            ->mapWithKeys(fn ($m) => [
                Carbon::parse($m->fecha)->toDateString() => (float) $m->gasto_real,
            ])
            ->all();
    }

    /**
     * Coste-cliente acumulado de una línea entre dos fechas (ambas inclusive).
     * Recorre mes a mes para respetar el prorrateo mensual.
     */
    public function costeClientePeriodo(Linea $linea, Carbon $desde, Carbon $hasta): float
    {
        $total  = 0.0;
        $cursor = $desde->copy()->startOfMonth();

        while ($cursor->lte($hasta)) {
            $mesData = $this->costeClienteMes($linea, $cursor->year, $cursor->month);
            foreach ($mesData as $fecha => $coste) {
                $f = Carbon::parse($fecha);
                if ($f->betweenIncluded($desde, $hasta)) {
                    $total += $coste;
                }
            }
            $cursor->addMonth();
        }

        return round($total, 2);
    }

    /**
     * Resumen de KPIs del cliente para una línea (o conjunto) en un rango.
     * Combina coste-cliente (calculado) con métricas reales (de metricas_diarias).
     *
     * @param  Collection<Linea>  $lineas
     * @return array{coste:float,clics:int,leads:float,impresiones:int,cpc:float,cpl:float,ctr:float}
     */
    public function kpis(Collection $lineas, Carbon $desde, Carbon $hasta): array
    {
        $coste = 0.0;
        foreach ($lineas as $linea) {
            $coste += $this->costeClientePeriodo($linea, $desde, $hasta);
        }

        $ids = $lineas->pluck('id');
        $reales = \App\Models\MetricaDiaria::whereIn('linea_id', $ids)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('SUM(clics) clics, SUM(leads) leads, SUM(impresiones) impresiones')
            ->first();

        $clics       = (int) ($reales->clics ?? 0);
        $leads       = (float) ($reales->leads ?? 0);
        $impresiones = (int) ($reales->impresiones ?? 0);

        return [
            'coste'       => round($coste, 2),
            'clics'       => $clics,
            'leads'       => $leads,
            'impresiones' => $impresiones,
            'cpc'         => $clics > 0 ? round($coste / $clics, 2) : 0.0,
            'cpl'         => $leads > 0 ? round($coste / $leads, 2) : 0.0,
            'ctr'         => $impresiones > 0 ? round($clics / $impresiones * 100, 2) : 0.0,
        ];
    }

    /**
     * Serie diaria combinada (para el gráfico del dashboard): por cada día,
     * coste-cliente + métricas reales.
     *
     * @return Collection<string,array>
     */
    public function serieDiaria(Linea $linea, int $anio, int $mes): Collection
    {
        $coste  = $this->costeClienteMes($linea, $anio, $mes);
        $reales = \App\Models\MetricaDiaria::where('linea_id', $linea->id)
            ->whereYear('fecha', $anio)->whereMonth('fecha', $mes)
            ->get()->keyBy(fn ($m) => Carbon::parse($m->fecha)->toDateString());

        return $coste->map(function ($costeDia, $fecha) use ($reales) {
            $r = $reales->get($fecha);
            $clics = (int) ($r->clics ?? 0);
            $leads = (float) ($r->leads ?? 0);
            return [
                'fecha' => $fecha,
                'coste' => $costeDia,
                'clics' => $clics,
                'leads' => $leads,
                'cpc'   => $clics > 0 ? round($costeDia / $clics, 2) : 0.0,
                'cpl'   => $leads > 0 ? round($costeDia / $leads, 2) : 0.0,
                'gasto_real' => (float) ($r->gasto_real ?? 0), // solo uso interno
            ];
        });
    }

    /**
     * Aplica los ajustes manuales del account manager sobre el coste diario.
     * Override con fecha concreta: pisa ese día. Override sin fecha: reparte el
     * importe entre los días del mes de forma proporcional al cálculo original.
     */
    protected function aplicarOverrides(Linea $linea, Collection $costeDiario): Collection
    {
        $overrides = Override::where('linea_id', $linea->id)
            ->where('campo', 'coste_cliente')
            ->get();

        if ($overrides->isEmpty()) {
            return $costeDiario;
        }

        // 1) Overrides por día concreto.
        foreach ($overrides->whereNotNull('fecha') as $ov) {
            $f = Carbon::parse($ov->fecha)->toDateString();
            if ($costeDiario->has($f)) {
                $costeDiario->put($f, round((float) $ov->valor, 2));
            }
        }

        // 2) Override de mes completo (redistribuye el total indicado).
        $mesOv = $overrides->whereNull('fecha')->last();
        if ($mesOv) {
            $totalActual = array_sum($costeDiario->all());
            $objetivo    = (float) $mesOv->valor;
            if ($totalActual > 0) {
                $factor = $objetivo / $totalActual;
                $costeDiario = $costeDiario->map(fn ($c) => round($c * $factor, 2));
            }
        }

        return $costeDiario;
    }
}

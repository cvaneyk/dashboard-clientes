<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Services\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Motor de precios con el modelo de porcentaje:
 *
 *     coste_cliente_dia = gasto_real_dia * (1 + porcentaje/100)
 *
 * Es la regla de la que cuelga todo lo que ve el cliente, así que aquí se fijan
 * los bordes: días fuera de tramo, tramos solapados, pausas y días sin gasto.
 */
class PricingPorcentajeTest extends TestCase
{
    use RefreshDatabase;

    private Linea $linea;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'activo'        => true,
        ]);

        $this->linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => 'INFANTIL',
            'match_key'  => 'infantil',
            'nivel'      => 'campania',
            'activo'     => true,
        ]);
    }

    private function gasto(string $fecha, float $importe): void
    {
        MetricaDiaria::create([
            'cliente_id' => $this->linea->cliente_id,
            'cuenta_id'  => $this->linea->cuenta_id,
            'linea_id'   => $this->linea->id,
            'plataforma' => 'meta',
            'match_key'  => $this->linea->match_key,
            'fecha'      => $fecha,
            'gasto_real' => $importe,
        ]);
    }

    private function tramo(string $desde, ?string $hasta, float $porcentaje, string $estado = 'activa'): void
    {
        PresupuestoEvento::create([
            'linea_id'   => $this->linea->id,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'porcentaje' => $porcentaje,
            'estado'     => $estado,
        ]);
    }

    /** @return array<string,float> */
    private function costes(): array
    {
        return app(PricingEngine::class)
            ->costeClienteMes($this->linea->fresh(), 2026, 4)
            ->all();
    }

    public function test_factura_el_gasto_real_mas_el_porcentaje(): void
    {
        $this->gasto('2026-04-10', 40);
        $this->tramo('2026-04-01', '2026-04-30', 25);

        $this->assertSame(50.0, $this->costes()['2026-04-10'], '40 € + 25 % = 50 €');
    }

    public function test_un_porcentaje_de_cero_factura_el_gasto_tal_cual(): void
    {
        $this->gasto('2026-04-10', 40);
        $this->tramo('2026-04-01', null, 0);

        $this->assertSame(40.0, $this->costes()['2026-04-10']);
    }

    public function test_un_dia_sin_gasto_no_factura_nada(): void
    {
        $this->tramo('2026-04-01', '2026-04-30', 25);

        $this->assertSame(0.0, $this->costes()['2026-04-10'], 'Sin gasto no hay margen que aplicar');
    }

    public function test_los_dias_fuera_del_tramo_no_se_facturan(): void
    {
        $this->gasto('2026-04-05', 100);
        $this->gasto('2026-04-20', 100);
        $this->tramo('2026-04-10', '2026-04-15', 50);

        $costes = $this->costes();
        $this->assertSame(0.0, $costes['2026-04-05'], 'Antes del tramo');
        $this->assertSame(0.0, $costes['2026-04-20'], 'Después del tramo');
    }

    public function test_un_tramo_sin_fecha_de_fin_sigue_vigente(): void
    {
        $this->gasto('2026-04-28', 80);
        $this->tramo('2026-04-01', null, 10);

        $this->assertSame(88.0, $this->costes()['2026-04-28']);
    }

    public function test_una_linea_pausada_no_factura_aunque_haya_gasto(): void
    {
        $this->gasto('2026-04-10', 200);
        $this->tramo('2026-04-01', null, 25, 'pausada');

        $this->assertSame(0.0, $this->costes()['2026-04-10']);
    }

    /** Corregir el margen se hace añadiendo un tramo encima, no editando el viejo. */
    public function test_si_dos_tramos_se_solapan_manda_el_que_empieza_mas_tarde(): void
    {
        $this->gasto('2026-04-20', 100);
        $this->tramo('2026-04-01', null, 20);
        $this->tramo('2026-04-15', null, 50);

        $this->assertSame(150.0, $this->costes()['2026-04-20'], 'Gana el tramo del día 15');
    }

    public function test_el_tramo_anterior_sigue_rigiendo_antes_del_cambio(): void
    {
        $this->gasto('2026-04-10', 100);
        $this->gasto('2026-04-20', 100);
        $this->tramo('2026-04-01', null, 20);
        $this->tramo('2026-04-15', null, 50);

        $costes = $this->costes();
        $this->assertSame(120.0, $costes['2026-04-10'], 'Antes del 15 rige el 20 %');
        $this->assertSame(150.0, $costes['2026-04-20']);
    }

    public function test_sin_ningun_tramo_el_mes_entero_va_a_cero(): void
    {
        $this->gasto('2026-04-10', 500);

        $this->assertSame(0.0, $this->costes()['2026-04-10'], 'Sin tramo no hay con qué facturar');
    }

    public function test_el_periodo_acumula_los_dias_del_rango(): void
    {
        $this->gasto('2026-04-10', 100);
        $this->gasto('2026-04-11', 100);
        $this->gasto('2026-04-12', 100);
        $this->tramo('2026-04-01', null, 10);

        $total = app(PricingEngine::class)->costeClientePeriodo(
            $this->linea->fresh(),
            \Carbon\Carbon::parse('2026-04-10'),
            \Carbon\Carbon::parse('2026-04-11')
        );

        $this->assertSame(220.0, $total, 'Solo los dos días del rango, con su margen');
    }
}

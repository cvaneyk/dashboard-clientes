<?php

namespace Tests\Feature;

use App\Filament\Resources\Lineas\Pages\ListLineas;
use App\Filament\Resources\Lineas\Tables\LineasTable;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tramos que se pisan entre sí.
 *
 * Dos cosas distintas que conviene no mezclar:
 *  - Qué tramo manda cuando varios cubren el mismo día (regla del motor).
 *  - Que el panel avise ANTES de crear uno que se solapa (red de seguridad).
 */
class TramosSolapadosTest extends TestCase
{
    use RefreshDatabase;

    private Linea $linea;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

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
            'nombre'     => 'INFANTIL_LEADADS',
            'producto'   => 'Infantil',
            'match_key'  => 'infantil',
            'nivel'      => 'campania',
            'activo'     => true,
        ]);
    }

    private function tramo(string $desde, ?string $hasta, float $porcentaje, string $estado = 'activa'): PresupuestoEvento
    {
        return PresupuestoEvento::create([
            'linea_id'   => $this->linea->id,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'porcentaje' => $porcentaje,
            'estado'     => $estado,
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

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    // --- Regla del motor -------------------------------------------------

    public function test_entre_tramos_solapados_gana_el_porcentaje_mas_alto(): void
    {
        $this->gasto('2026-09-10', 100);
        $this->tramo('2026-09-01', '2026-09-30', 20);
        $this->tramo('2026-09-01', '2026-09-30', 25);

        $costes = app(PricingEngine::class)->costeClienteMes($this->linea->fresh(), 2026, 9)->all();

        $this->assertSame(125.0, $costes['2026-09-10'], 'Manda el 25 %, no el último creado');
    }

    public function test_el_orden_de_creacion_no_cambia_el_resultado(): void
    {
        $this->gasto('2026-09-10', 100);
        // El mayor se crea PRIMERO: antes ganaba el último y ahora debe ganar él.
        $this->tramo('2026-09-01', '2026-09-30', 40);
        $this->tramo('2026-09-05', '2026-09-30', 10);

        $costes = app(PricingEngine::class)->costeClienteMes($this->linea->fresh(), 2026, 9)->all();

        $this->assertSame(140.0, $costes['2026-09-10']);
    }

    /** Pausar tiene que seguir funcionando: si ganara el % más alto, una pausa no se aplicaría nunca. */
    public function test_una_pausa_manda_sobre_cualquier_porcentaje(): void
    {
        $this->gasto('2026-09-10', 100);
        $this->tramo('2026-09-01', '2026-09-30', 40);
        $this->tramo('2026-09-08', '2026-09-30', 0, 'pausada');

        $costes = app(PricingEngine::class)->costeClienteMes($this->linea->fresh(), 2026, 9)->all();

        $this->assertSame(100.0, $costes['2026-09-10'], 'La pausa gana: gasto real, sin el 40 %');
        $this->assertSame(0.0, $costes['2026-09-05'], 'Antes de la pausa no hay gasto, así que 0');
    }

    public function test_fuera_del_solape_sigue_rigiendo_cada_tramo(): void
    {
        $this->gasto('2026-09-03', 100);
        $this->gasto('2026-09-20', 100);
        $this->tramo('2026-09-01', '2026-09-10', 10);
        $this->tramo('2026-09-05', '2026-09-30', 30);

        $costes = app(PricingEngine::class)->costeClienteMes($this->linea->fresh(), 2026, 9)->all();

        $this->assertSame(110.0, $costes['2026-09-03'], 'Solo lo cubre el del 10 %');
        $this->assertSame(130.0, $costes['2026-09-20'], 'Solo lo cubre el del 30 %');
    }

    // --- Aviso en el panel -----------------------------------------------

    /** Las líneas tal y como llegan a la acción masiva: con sus tramos cargados. */
    private function seleccion(): \Illuminate\Support\Collection
    {
        return collect(Linea::with('presupuestoEventos')->get()->all());
    }

    public function test_detecta_el_solape_y_nombra_la_linea_afectada(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);

        $solapes = LineasTable::solapes($this->seleccion(), '2026-09-10', '2026-09-20');

        $this->assertCount(1, $solapes);
        $this->assertSame('INFANTIL_LEADADS', $solapes[0]['linea'], 'El nombre de campaña, no el producto');
        $this->assertSame(20.0, $solapes[0]['porcentaje']);
    }

    public function test_el_aviso_dice_que_ganara_el_porcentaje_mas_alto(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);

        $aviso = LineasTable::avisoSolape($this->seleccion(), '2026-09-10', '2026-09-20');

        $this->assertStringContainsString('ya tienen un tramo en esas fechas', $aviso);
        $this->assertStringContainsString('INFANTIL_LEADADS', $aviso);
        $this->assertStringNotContainsString('>Infantil<', $aviso, 'El producto no distingue entre líneas');
        // El color del texto va explícito o el tema oscuro lo deja ilegible.
        $this->assertStringContainsString('color:', $aviso);
        $this->assertStringContainsString('20,00 %', $aviso);
        $this->assertStringContainsString('el porcentaje más alto', $aviso);
    }

    public function test_fechas_que_no_se_tocan_no_son_solape(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);

        $this->assertSame([], LineasTable::solapes($this->seleccion(), '2026-10-01', '2026-10-31'));
        $this->assertSame('', LineasTable::avisoSolape($this->seleccion(), '2026-10-01', '2026-10-31'));
    }

    /**
     * Corregir el porcentaje de un tramo se hace repitiendo la acción con las
     * mismas fechas: eso lo actualiza en sitio, así que no es un solape y no
     * debe pedir confirmación.
     */
    public function test_repetir_las_mismas_fechas_no_cuenta_como_solape(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);

        $this->assertSame([], LineasTable::solapes($this->seleccion(), '2026-09-01', '2026-09-30'));
    }

    public function test_repetir_un_tramo_abierto_con_la_misma_fecha_tampoco_solapa(): void
    {
        $this->tramo('2026-09-01', null, 20);

        $this->assertSame([], LineasTable::solapes($this->seleccion(), '2026-09-01', null));
        $this->assertCount(1, LineasTable::solapes($this->seleccion(), '2026-09-01', '2026-09-30'),
            'Cambiar el fin sí crea un tramo distinto, y eso sí se solapa');
    }

    /** Un tramo sin fecha de fin pisa cualquier cosa posterior. */
    public function test_un_tramo_abierto_solapa_con_todo_lo_que_venga_despues(): void
    {
        $this->tramo('2026-09-01', null, 20);

        $this->assertCount(1, LineasTable::solapes($this->seleccion(), '2027-05-01', '2027-05-31'));
    }

    public function test_un_tramo_nuevo_sin_fin_solapa_con_los_anteriores(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);

        $this->assertCount(1, LineasTable::solapes($this->seleccion(), '2026-09-15', null));
        $this->assertSame([], LineasTable::solapes($this->seleccion(), '2026-10-01', null));
    }

    /** Con solape, no se puede guardar sin marcar la casilla. */
    public function test_el_solape_no_se_puede_guardar_sin_confirmar(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);
        $this->actingAs($this->admin(), 'admin');

        Livewire::test(ListLineas::class)
            ->mountTableBulkAction('asignar_objetivo', [$this->linea])
            ->setTableBulkActionData([
                'desde'      => '2026-09-10',
                'hasta'      => '2026-09-20',
                'porcentaje' => 25,
                'estado'     => 'activa',
            ])
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['confirmar_solape']);

        $this->assertSame(1, PresupuestoEvento::count(), 'No se ha creado nada');
    }

    public function test_confirmando_el_solape_si_se_crea(): void
    {
        $this->tramo('2026-09-01', '2026-09-30', 20);
        $this->actingAs($this->admin(), 'admin');

        Livewire::test(ListLineas::class)
            ->mountTableBulkAction('asignar_objetivo', [$this->linea])
            ->setTableBulkActionData([
                'desde'            => '2026-09-10',
                'hasta'            => '2026-09-20',
                'porcentaje'       => 25,
                'estado'           => 'activa',
                'confirmar_solape' => true,
            ])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2, PresupuestoEvento::count());
    }

    /** Sin solape no hay casilla que marcar: el flujo normal no cambia. */
    public function test_sin_solape_se_guarda_directamente(): void
    {
        $this->actingAs($this->admin(), 'admin');

        Livewire::test(ListLineas::class)
            ->mountTableBulkAction('asignar_objetivo', [$this->linea])
            ->setTableBulkActionData([
                'desde'      => '2026-09-01',
                'hasta'      => '2026-09-30',
                'porcentaje' => 25,
                'estado'     => 'activa',
            ])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(1, PresupuestoEvento::count());
    }
}

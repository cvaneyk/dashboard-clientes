<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard interno: la misma pantalla con el gasto REAL de plataforma en vez
 * del coste facturado al cliente.
 *
 * Es la única vista del portal que enseña gasto_real, la cifra que toda la
 * arquitectura se ha cuidado de no mostrar jamás. Por eso aquí pesa más
 * comprobar quién NO entra que comprobar que los números salen.
 */
class DashboardInternoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $this->cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'activo'        => true,
        ]);

        $linea = Linea::create([
            'cliente_id' => $this->cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => 'INFANTIL',
            'match_key'  => 'infantil',
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        // Se le factura 3.100 €/mes y a la plataforma se le pagan 40 €/día.
        PresupuestoEvento::create([
            'linea_id'       => $linea->id,
            'desde'          => now()->startOfMonth()->toDateString(),
            'porcentaje'     => 25,
            'estado'         => 'activa',
        ]);

        MetricaDiaria::create([
            'cliente_id'  => $this->cliente->id,
            'cuenta_id'   => $cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => 'meta',
            'match_key'   => 'infantil',
            'fecha'       => now()->toDateString(),
            'impresiones' => 1000,
            'clics'       => 50,
            'leads'       => 3,
            'gasto_real'  => 40.00,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Account manager',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    private function usuarioCliente(): User
    {
        return User::create([
            'name'       => 'Cliente',
            'email'      => 'cliente@ejemplo.es',
            'password'   => 'secretito',
            'cliente_id' => $this->cliente->id,
        ]);
    }

    public function test_un_usuario_de_cliente_no_entra_ni_conociendo_la_url(): void
    {
        $this->actingAs($this->usuarioCliente())
            ->get('/dashboard/interno')
            ->assertForbidden();
    }

    public function test_sin_sesion_no_se_entra(): void
    {
        $this->get('/dashboard/interno')->assertRedirect('/login');
    }

    public function test_el_admin_ve_el_gasto_real_y_no_el_facturado(): void
    {
        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard/interno')
            ->assertSuccessful();

        // 40 € de gasto real ese día, no el prorrateo del objetivo mensual.
        $respuesta->assertSee('"c":40', false);
        $respuesta->assertSee('Gasto real');
        $respuesta->assertSee('Datos internos');
    }

    public function test_la_vista_del_cliente_sigue_mostrando_lo_facturado(): void
    {
        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful();

        $respuesta->assertSee('Inversión');
        $respuesta->assertDontSee('Datos internos');
        // El prorrateo de 3.100 € entre los días del mes nunca da 40.
        $respuesta->assertDontSee('"c":40', false);
    }

    public function test_el_gasto_real_no_viaja_a_la_vista_del_cliente(): void
    {
        $this->actingAs($this->usuarioCliente())
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertDontSee('"c":40', false)
            ->assertDontSee('Gasto real');
    }

    public function test_el_selector_de_cliente_sigue_funcionando_en_la_interna(): void
    {
        $otro = Cliente::create(['nombre' => 'Otra escuela', 'slug' => 'otra-escuela', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard/interno?cliente=' . $otro->slug)
            ->assertSuccessful()
            ->assertSee('Otra escuela')
            ->assertSee('Datos internos');
    }

    /** Cambiar de cliente desde la vista interna no puede devolverte a la del cliente. */
    public function test_el_selector_de_cliente_se_queda_en_la_vista_interna(): void
    {
        Cliente::create(['nombre' => 'Otra escuela', 'slug' => 'otra-escuela', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard/interno')
            ->assertSuccessful()
            ->assertSee('action="' . route('portal.dashboard.interno') . '"', false)
            ->assertDontSee('action="' . route('portal.dashboard') . '"', false);
    }

    public function test_el_selector_de_cliente_apunta_al_dashboard_normal_en_la_vista_de_cliente(): void
    {
        Cliente::create(['nombre' => 'Otra escuela', 'slug' => 'otra-escuela', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('action="' . route('portal.dashboard') . '"', false);
    }

    public function test_el_panel_enlaza_a_la_vista_interna(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Dashboard interno');
    }
}

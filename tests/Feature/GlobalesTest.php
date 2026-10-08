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
 * Gastos globales: el gasto de todas las líneas unificado por canal o cliente.
 *
 * Como mezcla clientes en una sola página, lo que más pesa es el aislamiento:
 * que un cliente no vea a otros y que gasto_real no salga de la vista interna.
 */
class GlobalesTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $uni;
    private Cliente $escuela;

    protected function setUp(): void
    {
        parent::setUp();

        // 40 € de gasto con un 25 % → 50 € facturados; 20 € al 10 % → 22 €.
        $this->uni     = $this->cliente('Mi universidad', 'mi-universidad', 'meta', 40.00, 25);
        $this->escuela = $this->cliente('Otra escuela', 'otra-escuela', 'google', 20.00, 10);
    }

    private function cliente(string $nombre, string $slug, string $plataforma, float $gasto, float $porcentaje): Cliente
    {
        $cliente = Cliente::create(['nombre' => $nombre, 'slug' => $slug, 'activo' => true]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => $plataforma,
            'nombre_cuenta' => $nombre,
            'activo'        => true,
        ]);

        $linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => $plataforma,
            'nombre'     => 'CAMPANA ' . strtoupper($slug),
            'match_key'  => 'campana-' . $slug,
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        PresupuestoEvento::create([
            'linea_id'   => $linea->id,
            'desde'      => now()->startOfMonth()->toDateString(),
            'porcentaje' => $porcentaje,
            'estado'     => 'activa',
        ]);

        MetricaDiaria::create([
            'cliente_id'  => $cliente->id,
            'cuenta_id'   => $cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => $plataforma,
            'match_key'   => $linea->match_key,
            'fecha'       => now()->toDateString(),
            'impresiones' => 1000,
            'clics'       => 50,
            'leads'       => 3,
            'gasto_real'  => $gasto,
        ]);

        return $cliente;
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

    private function usuarioDe(Cliente $cliente): User
    {
        return User::create([
            'name'       => 'Cliente',
            'email'      => 'cliente@ejemplo.es',
            'password'   => 'secretito',
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_sin_sesion_no_se_entra(): void
    {
        $this->get('/globales')->assertRedirect('/login');
        $this->get('/globales/interno')->assertRedirect('/login');
    }

    public function test_el_cliente_solo_ve_lo_suyo_y_solo_lo_facturado(): void
    {
        $this->actingAs($this->usuarioDe($this->uni))
            ->get('/globales')
            ->assertSuccessful()
            ->assertSee('"c":50', false)
            ->assertSee('"c","Inversi', false)
            // Ni el otro cliente, ni el gasto real, ni el margen.
            ->assertDontSee('otra-escuela')
            ->assertDontSee('Otra escuela')
            ->assertDontSee('"g":', false)
            ->assertDontSee('Gasto real')
            ->assertDontSee('Margen')
            // Con un solo cliente no hay interruptor de cliente.
            ->assertDontSee('id="groupBy"', false);
    }

    public function test_el_cliente_no_entra_en_la_vista_interna(): void
    {
        $this->actingAs($this->usuarioDe($this->uni))
            ->get('/globales/interno')
            ->assertForbidden();
    }

    public function test_el_admin_en_vista_de_cliente_ve_todos_los_clientes_sin_gasto_real(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/globales')
            ->assertSuccessful()
            ->assertSee('"c":50', false)
            ->assertSee('"c":22', false)
            ->assertSee('Otra escuela')
            ->assertSee('id="groupBy"', false)
            ->assertDontSee('"g":', false)
            ->assertDontSee('Datos internos');
    }

    public function test_la_vista_interna_trae_gasto_real_facturado_y_margen(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/globales/interno')
            ->assertSuccessful()
            ->assertSee('"g":40', false)
            ->assertSee('"g":20', false)
            ->assertSee('Gasto real')
            ->assertSee('Facturado')
            ->assertSee('Margen')
            ->assertSee('Datos internos');
    }

    public function test_los_dashboards_enlazan_a_su_vista_de_globales(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get('/dashboard')
            ->assertSee('href="' . route('portal.globales') . '"', false);

        $this->actingAs($admin, 'admin')
            ->get('/dashboard/interno')
            ->assertSee('href="' . route('portal.globales.interno') . '"', false);
    }

    public function test_el_dashboard_del_cliente_no_enlaza_a_la_interna(): void
    {
        $this->actingAs($this->usuarioDe($this->uni))
            ->get('/dashboard')
            ->assertSee('href="' . route('portal.globales') . '"', false)
            ->assertDontSee(route('portal.globales.interno'));
    }
}

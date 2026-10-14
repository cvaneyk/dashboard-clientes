<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selector de cliente en el dashboard.
 *
 * El grueso de estos tests no es la funcionalidad, es el aislamiento: en cuanto
 * el cliente a pintar puede venir de la URL, un portal multicliente se
 * convierte en una fuga de datos si alguien se fía del parámetro.
 */
class DashboardMulticlienteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * OJO con los nombres: el dataset viaja como JSON y json_encode escapa lo
     * no-ASCII (la Ñ sale como Ñ). Con acentos, un assertDontSee
     * pasaría siempre, por buscar algo que no puede aparecer, y los tests de
     * aislamiento —que son el motivo de este fichero— no comprobarían nada.
     */
    private function campana(string $slug): string
    {
        return 'CAMPANA DE ' . strtoupper($slug);
    }

    private function cliente(string $nombre, string $slug): Cliente
    {
        $cliente = Cliente::create(['nombre' => $nombre, 'slug' => $slug, 'activo' => true]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => $nombre . ' · Meta',
            'activo'        => true,
        ]);

        $linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => $this->campana($slug),
            'match_key'  => Linea::normalizar($this->campana($slug)),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        MetricaDiaria::create([
            'cliente_id'  => $cliente->id,
            'cuenta_id'   => $cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => 'meta',
            'match_key'   => $linea->match_key,
            'fecha'       => now()->toDateString(),
            'impresiones' => 1000,
            'clics'       => 50,
            'leads'       => 3,
            'gasto_real'  => 20,
        ]);

        return $cliente;
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Account manager',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    private function usuarioDe(Cliente $cliente): User
    {
        return User::create([
            'name'       => 'Usuario ' . $cliente->nombre,
            'email'      => $cliente->slug . '@ejemplo.es',
            'password'   => 'secretito',
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_el_admin_ve_el_selector_con_todos_los_clientes(): void
    {
        $uno = $this->cliente('Mi universidad', 'mi-universidad');
        $dos = $this->cliente('Otra escuela', 'otra-escuela');

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('Mi universidad')
            ->assertSee('Otra escuela');
    }

    public function test_el_admin_cambia_de_cliente_por_el_selector(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');
        $this->cliente('Otra escuela', 'otra-escuela');

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard?cliente=otra-escuela')
            ->assertSuccessful()
            ->assertSee('CAMPANA DE OTRA-ESCUELA')
            ->assertDontSee('CAMPANA DE MI-UNIVERSIDAD');
    }

    public function test_un_cliente_no_puede_espiar_a_otro_por_la_url(): void
    {
        $suyo  = $this->cliente('Mi universidad', 'mi-universidad');
        $ajeno = $this->cliente('Otra escuela', 'otra-escuela');

        $respuesta = $this->actingAs($this->usuarioDe($suyo))
            ->get('/dashboard?cliente=' . $ajeno->slug)
            ->assertSuccessful();

        // El parámetro se ignora: sigue viendo lo suyo, sin filtrar siquiera
        // que ese otro cliente existe.
        $respuesta->assertSee('CAMPANA DE MI-UNIVERSIDAD');
        $respuesta->assertDontSee('CAMPANA DE OTRA-ESCUELA');
        $respuesta->assertDontSee('Otra escuela');
    }

    public function test_un_cliente_no_ve_el_selector(): void
    {
        $suyo = $this->cliente('Mi universidad', 'mi-universidad');
        $this->cliente('Otra escuela', 'otra-escuela');

        $this->actingAs($this->usuarioDe($suyo))
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertDontSee('<select name="cliente"', false);
    }

    public function test_un_slug_inventado_cae_al_primer_cliente_permitido(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard?cliente=no-existe')
            ->assertSuccessful()
            ->assertSee('Mi universidad');
    }

    public function test_el_admin_no_ve_clientes_desactivados(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');
        $baja = $this->cliente('Cliente de baja', 'cliente-de-baja');
        $baja->update(['activo' => false]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard?cliente=cliente-de-baja')
            ->assertSuccessful()
            ->assertSee('Mi universidad')
            ->assertDontSee('CAMPANA DE CLIENTE-DE-BAJA');
    }

    public function test_recuerda_el_cliente_elegido_entre_visitas(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');
        $this->cliente('Otra escuela', 'otra-escuela');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/dashboard?cliente=otra-escuela');

        $this->actingAs($admin, 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('CAMPANA DE OTRA-ESCUELA');
    }

    public function test_el_admin_sin_clientes_dados_de_alta_vuelve_al_panel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertRedirect('/admin');
    }

    public function test_sin_sesion_no_se_entra(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');

        $this->get('/dashboard?cliente=mi-universidad')->assertRedirect('/login');
    }

    /** El dashboard es la vista del cliente: el gasto de plataforma no sale ni para el admin. */
    public function test_el_gasto_real_no_viaja_al_navegador(): void
    {
        $this->cliente('Mi universidad', 'mi-universidad');

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertDontSee('gasto_real');
    }
}

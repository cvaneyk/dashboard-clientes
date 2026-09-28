<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Cómo llega un admin al dashboard del cliente.
 *
 * El panel y el portal usan guards distintos a propósito, y eso deja dos
 * costuras que hay que mantener cosidas: que entrar por /login no obligue a
 * identificarse dos veces, y que '/' no sea un callejón sin salida.
 */
class AccesoAdminAlDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Account manager',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    public function test_entrar_por_login_deja_al_admin_dentro_del_panel(): void
    {
        $this->admin();

        $this->post('/login', ['email' => 'admin@olimedia.es', 'password' => 'secretito'])
            ->assertRedirect('/admin');

        // Las dos sesiones quedan abiertas: la del portal y la del panel. Sin
        // la segunda, /admin volvería a pedir las mismas credenciales.
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertTrue(Auth::guard('admin')->check(), 'El admin no debería tener que loguearse dos veces');
    }

    public function test_el_admin_llega_al_dashboard_con_la_sesion_del_panel(): void
    {
        Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('Mi universidad');
    }

    public function test_el_panel_enlaza_al_dashboard_del_cliente(): void
    {
        Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Ver dashboard de cliente');
    }

    public function test_la_raiz_sigue_mandando_al_admin_a_su_panel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/')
            ->assertRedirect('/admin');
    }

    public function test_el_cliente_sigue_yendo_a_su_dashboard(): void
    {
        $cliente = Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

        $usuario = User::create([
            'name'       => 'Cliente',
            'email'      => 'cliente@ejemplo.es',
            'password'   => 'secretito',
            'cliente_id' => $cliente->id,
        ]);

        $this->post('/login', ['email' => 'cliente@ejemplo.es', 'password' => 'secretito'])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertFalse(Auth::guard('admin')->check(), 'Un cliente jamás abre sesión de panel');

        $this->actingAs($usuario)->get('/')->assertRedirect(route('portal.dashboard'));
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manuales de conexión: solo para admins, uno por plataforma y con las URLs de
 * la instalación en la que se leen, no con las de ningún despliegue concreto.
 */
class ManualesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Account manager',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    private function usuarioCliente(): User
    {
        $cliente = Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

        return User::create([
            'name'       => 'Cliente',
            'email'      => 'cliente@example.com',
            'password'   => 'secretito',
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_cada_plataforma_tiene_su_manual(): void
    {
        $admin = $this->admin();

        foreach (Cuenta::PLATAFORMAS as $clave => $nombre) {
            $this->actingAs($admin, 'admin')
                ->get("/manuales/{$clave}")
                ->assertOk()
                ->assertSee($nombre)
                ->assertSee('Problemas frecuentes');
        }
    }

    public function test_el_indice_enlaza_todas_las_plataformas(): void
    {
        $respuesta = $this->actingAs($this->admin(), 'admin')->get('/manuales')->assertOk();

        foreach (array_keys(Cuenta::PLATAFORMAS) as $clave) {
            $respuesta->assertSee(route('portal.manuales.show', $clave));
        }
    }

    public function test_la_url_de_retorno_es_la_de_esta_instalacion(): void
    {
        $admin = $this->admin();

        foreach (['meta', 'google', 'linkedin', 'spotify'] as $clave) {
            $this->actingAs($admin, 'admin')
                ->get("/manuales/{$clave}")
                ->assertSee(route("conexiones.{$clave}.callback"))
                ->assertDontSee('{url_retorno}');
        }
    }

    public function test_ningun_manual_lleva_el_dominio_de_un_despliegue(): void
    {
        foreach (glob(resource_path('manuales/*.md')) as $fichero) {
            $texto = file_get_contents($fichero);

            $this->assertStringNotContainsString('carlosvaneyk.online', $texto, $fichero);
            $this->assertStringNotContainsString('olimedia.es', $texto, $fichero);
        }
    }

    public function test_un_usuario_de_cliente_no_entra(): void
    {
        $usuario = $this->usuarioCliente();

        $this->actingAs($usuario, 'web')->get('/manuales')->assertForbidden();
        $this->actingAs($usuario, 'web')->get('/manuales/google')->assertForbidden();
    }

    public function test_sin_sesion_redirige_al_login(): void
    {
        $this->get('/manuales')->assertRedirect();
    }

    public function test_una_plataforma_desconocida_da_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/manuales/noexiste')->assertNotFound();
        $this->actingAs($admin, 'admin')->get('/manuales/..%2F..%2Fweb')->assertNotFound();
    }

    public function test_el_panel_enlaza_los_manuales_desde_el_menu_y_la_ficha_del_canal(): void
    {
        $cliente = Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);
        $cuenta  = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'spotify',
            'nombre_cuenta' => 'Spotify',
            'activo'        => true,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Manuales de conexión')
            ->assertSee('/manuales');

        $this->actingAs($admin, 'admin')
            ->get(CuentaResource::getUrl('edit', ['record' => $cuenta]))
            ->assertOk()
            ->assertSee(route('portal.manuales.show', 'spotify'));
    }

    public function test_el_dashboard_del_cliente_no_enlaza_los_manuales(): void
    {
        $this->actingAs($this->usuarioCliente(), 'web')
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(route('portal.manuales'));
    }

    public function test_el_dashboard_enlaza_los_manuales_al_admin(): void
    {
        Cliente::create(['nombre' => 'Mi universidad', 'slug' => 'mi-universidad', 'activo' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('portal.manuales'));
    }
}

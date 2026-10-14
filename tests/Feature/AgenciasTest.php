<?php

namespace Tests\Feature;

use App\Filament\Resources\Agencias\Pages\CreateAgencia;
use App\Models\Agencia;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PlantillaEmail;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\Informes\InformesSemanales;
use App\Services\Sync\Conectores;
use App\Support\AgenciaActual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Varias agencias en la misma instalación.
 *
 * Lo que importa aquí es el AISLAMIENTO: una agencia no puede ver ni tocar
 * nada de otra, venga por el panel, por el dashboard, por un id en la URL o
 * por un proceso automático (sincronización, informes).
 *
 * Nombres sin acentos a propósito (ver DashboardMulticlienteTest): el dataset
 * del dashboard viaja como JSON escapado y un assertDontSee con acentos
 * pasaría siempre.
 */
class AgenciasTest extends TestCase
{
    use RefreshDatabase;

    private Agencia $alfa;
    private Agencia $beta;
    private Cliente $clienteAlfa;
    private Cliente $clienteBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = Agencia::create([
            'nombre'       => 'Agencia Alfa',
            'slug'         => 'alfa',
            'activo'       => true,
            'credenciales' => ['meta_app_id' => 'APP-ALFA', 'meta_app_secret' => 'SECRETO-ALFA'],
        ]);
        $this->beta = Agencia::create(['nombre' => 'Agencia Beta', 'slug' => 'beta', 'activo' => true]);

        $this->clienteAlfa = $this->cliente($this->alfa, 'Cliente Alfa Uno', 'alfa-uno');
        $this->clienteBeta = $this->cliente($this->beta, 'Cliente Beta Uno', 'beta-uno');
    }

    private function cliente(Agencia $agencia, string $nombre, string $slug): Cliente
    {
        $cliente = Cliente::create(['agencia_id' => $agencia->id, 'nombre' => $nombre, 'slug' => $slug, 'activo' => true]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Cuenta ' . $nombre,
            'external_id'   => '123',
            'activo'        => true,
        ]);

        $linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => 'CAMPANA ' . strtoupper($slug),
            'match_key'  => Linea::normalizar('CAMPANA ' . $slug),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        PresupuestoEvento::create([
            'linea_id' => $linea->id, 'desde' => '2026-09-01', 'porcentaje' => 20, 'estado' => 'activa',
        ]);

        MetricaDiaria::create([
            'cliente_id'  => $cliente->id,
            'cuenta_id'   => $cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => 'meta',
            'match_key'   => $linea->match_key,
            'fecha'       => '2026-09-30',
            'impresiones' => 1000,
            'clics'       => 50,
            'leads'       => 3,
            'gasto_real'  => 100,
        ]);

        return $cliente;
    }

    private function adminDe(Agencia $agencia, string $email): User
    {
        return User::create([
            'name' => 'Admin ' . $agencia->nombre, 'email' => $email, 'password' => 'secretito',
            'agencia_id' => $agencia->id, 'es_admin' => true,
        ]);
    }

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Plataforma', 'email' => 'super@example.com', 'password' => 'secretito',
            'es_admin' => true, 'es_superadmin' => true,
        ]);
    }

    // ------------------------------------------------------------ datos

    public function test_el_global_scope_solo_deja_ver_lo_de_la_agencia(): void
    {
        AgenciaActual::como($this->alfa->id, function () {
            $this->assertSame(['Cliente Alfa Uno'], Cliente::pluck('nombre')->all());
            $this->assertSame(1, Cuenta::count());
            $this->assertSame(1, Linea::count());
            $this->assertSame(1, MetricaDiaria::count());
            $this->assertSame(1, PresupuestoEvento::count());
            $this->assertSame(['Agencia Alfa'], Agencia::pluck('nombre')->all());
            $this->assertNull(Cuenta::find($this->clienteBeta->cuentas()->withoutGlobalScopes()->value('id')));
        });

        // Sin usuario (scheduler, comandos) no hay filtro.
        $this->assertSame(2, Cliente::count());
    }

    public function test_lo_que_crea_el_admin_de_una_agencia_es_de_su_agencia(): void
    {
        $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin');

        $cliente = Cliente::create(['nombre' => 'Nuevo', 'slug' => 'nuevo', 'activo' => true]);
        $usuario = User::create([
            'name' => 'Colega', 'email' => 'colega@alfa.com', 'password' => 'secretito',
            'es_admin' => true, 'agencia_id' => $this->beta->id, // intento de colarlo en otra agencia
        ]);

        $this->assertSame($this->alfa->id, $cliente->agencia_id);
        $this->assertSame($this->alfa->id, $usuario->fresh()->agencia_id);
    }

    public function test_un_usuario_de_cliente_es_de_la_agencia_de_su_cliente(): void
    {
        $usuario = User::create([
            'name' => 'Cliente', 'email' => 'c@beta.com', 'password' => 'secretito',
            'cliente_id' => $this->clienteBeta->id,
        ]);

        $this->assertSame($this->beta->id, $usuario->agencia_id);
    }

    // ------------------------------------------------------------ panel

    public function test_el_admin_de_una_agencia_solo_ve_sus_clientes_en_el_panel(): void
    {
        $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin')
            ->get('/admin/clientes')
            ->assertSuccessful()
            ->assertSee('Cliente Alfa Uno')
            ->assertDontSee('Cliente Beta Uno');
    }

    public function test_el_admin_de_una_agencia_no_abre_registros_de_otra_por_url(): void
    {
        $cuentaBeta = Cuenta::withoutGlobalScopes()->where('cliente_id', $this->clienteBeta->id)->value('id');

        $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin')
            ->get("/admin/cuentas/{$cuentaBeta}/edit")
            ->assertNotFound();

        $this->get("/admin/agencias/{$this->beta->id}/edit")->assertNotFound();
    }

    public function test_el_admin_de_una_agencia_solo_ve_usuarios_de_la_suya(): void
    {
        $this->superadmin();
        $this->adminDe($this->beta, 'b@beta.com');

        $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin')
            ->get('/admin/users')
            ->assertSuccessful()
            ->assertSee('a@alfa.com')
            ->assertDontSee('b@beta.com')
            ->assertDontSee('super@example.com');
    }

    public function test_el_admin_de_una_agencia_no_puede_crear_agencias(): void
    {
        $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin')
            ->get('/admin/agencias/create')
            ->assertForbidden();
    }

    public function test_el_superadmin_ve_todas_las_agencias(): void
    {
        $this->actingAs($this->superadmin(), 'admin')
            ->get('/admin/clientes')
            ->assertSuccessful()
            ->assertSee('Cliente Alfa Uno')
            ->assertSee('Cliente Beta Uno');

        $this->get('/admin/agencias/create')->assertSuccessful();
    }

    public function test_el_superadmin_crea_una_agencia_con_su_primer_admin_y_sus_apps(): void
    {
        $this->actingAs($this->superadmin(), 'admin');

        Livewire::test(CreateAgencia::class)
            ->fillForm([
                'nombre'                       => 'Agencia Gamma',
                'slug'                         => 'gamma',
                'activo'                       => true,
                'credenciales.meta_app_id'     => 'APP-GAMMA',
                'credenciales.meta_app_secret' => 'SECRETO-GAMMA',
                'admin_nombre'                 => 'Gema',
                'admin_email'                  => 'gema@gamma.com',
                'admin_password'               => 'una-clave-larga',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $gamma = Agencia::where('slug', 'gamma')->sole();
        $admin = User::where('email', 'gema@gamma.com')->sole();

        $this->assertSame('APP-GAMMA', $gamma->credenciales['meta_app_id']);
        $this->assertSame($gamma->id, $admin->agencia_id);
        $this->assertTrue($admin->es_admin);
        $this->assertFalse($admin->es_superadmin);
        $this->assertTrue(Hash::check('una-clave-larga', $admin->password));
    }

    public function test_una_agencia_desactivada_no_entra_al_panel_ni_ve_clientes(): void
    {
        $admin = $this->adminDe($this->alfa, 'a@alfa.com');
        $this->alfa->update(['activo' => false]);

        $this->assertTrue($admin->clientesVisibles()->isEmpty());
        $this->actingAs($admin, 'admin')->get('/admin')->assertForbidden();
    }

    public function test_un_cliente_de_una_agencia_desactivada_sale_sin_bucle_de_redirecciones(): void
    {
        $usuario = User::create([
            'name' => 'Cliente', 'email' => 'c@alfa.com', 'password' => 'secretito',
            'cliente_id' => $this->clienteAlfa->id,
        ]);
        $this->alfa->update(['activo' => false]);

        $this->actingAs($usuario)
            ->followingRedirects()
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('El acceso de tu agencia está desactivado.');

        $this->assertGuest();
    }

    // ------------------------------------------------------- dashboard

    public function test_el_dashboard_de_la_agencia_solo_ofrece_sus_clientes_y_lleva_su_marca(): void
    {
        $respuesta = $this->actingAs($this->adminDe($this->alfa, 'a@alfa.com'), 'admin')
            ->get('/dashboard?cliente=beta-uno') // un slug de otra agencia se ignora
            ->assertSuccessful();

        $respuesta->assertSee('CAMPANA ALFA-UNO');
        $respuesta->assertDontSee('CAMPANA BETA-UNO');
        $respuesta->assertDontSee('Cliente Beta Uno');
        $respuesta->assertSee('Agencia Alfa');
        $respuesta->assertDontSee('Agencia Beta');
    }

    public function test_el_superadmin_ve_el_dashboard_con_la_marca_de_la_agencia_del_cliente(): void
    {
        $this->actingAs($this->superadmin(), 'admin')
            ->get('/dashboard?cliente=beta-uno')
            ->assertSuccessful()
            ->assertSee('CAMPANA BETA-UNO')
            ->assertSee('Agencia Beta');
    }

    // ---------------------------------------------------- credenciales

    public function test_cada_cuenta_usa_las_apps_de_su_agencia_y_nunca_las_del_env(): void
    {
        config(['services.meta.app_id' => 'APP-DEL-ENV', 'services.meta.app_secret' => 'SECRETO-DEL-ENV']);

        $cuentaAlfa = Cuenta::where('cliente_id', $this->clienteAlfa->id)->first();
        $cuentaBeta = Cuenta::where('cliente_id', $this->clienteBeta->id)->first();

        Conectores::para($cuentaAlfa);
        $this->assertSame('APP-ALFA', config('services.meta.app_id'));
        $this->assertSame('SECRETO-ALFA', config('services.meta.app_secret'));

        // Beta no tiene app de Meta: no hereda ni la del .env ni la de Alfa.
        Conectores::para($cuentaBeta);
        $this->assertNull(config('services.meta.app_id'));
        $this->assertNull(config('services.meta.app_secret'));
    }

    public function test_las_credenciales_se_guardan_cifradas(): void
    {
        $crudo = DB::table('agencias')->where('id', $this->alfa->id)->value('credenciales');

        $this->assertStringNotContainsString('SECRETO-ALFA', $crudo);
        $this->assertSame('SECRETO-ALFA', $this->alfa->fresh()->credenciales['meta_app_secret']);
    }

    // ------------------------------------------------------- informes

    public function test_el_resumen_interno_de_una_agencia_solo_lleva_sus_cuentas(): void
    {
        [$desde, $hasta] = InformesSemanales::semanaAnterior(Carbon::parse('2026-10-05'));

        $cuerpo = app(InformesSemanales::class)->paraAdmin($desde, $hasta, $this->alfa->id)['cuerpo'];

        $this->assertStringContainsString('Cliente Alfa Uno', $cuerpo);
        $this->assertStringNotContainsString('Cliente Beta Uno', $cuerpo);
    }

    public function test_el_resumen_interno_va_a_los_destinatarios_de_cada_agencia(): void
    {
        $this->alfa->update(['email_informes' => 'jefa@alfa.com']);
        $this->adminDe($this->alfa, 'a@alfa.com');
        $this->adminDe($this->beta, 'b@beta.com');
        $this->superadmin();

        $this->assertSame(['jefa@alfa.com'], InformesSemanales::destinatariosAdmin($this->alfa->id));
        $this->assertSame(['b@beta.com'], InformesSemanales::destinatariosAdmin($this->beta->id));
    }

    public function test_cada_agencia_nueva_arranca_con_sus_propias_plantillas(): void
    {
        $this->assertSame(2, PlantillaEmail::withoutGlobalScopes()->where('agencia_id', $this->alfa->id)->count());

        PlantillaEmail::withoutGlobalScopes()
            ->where('agencia_id', $this->alfa->id)
            ->where('clave', PlantillaEmail::INFORME_SEMANAL)
            ->update(['asunto' => 'Asunto propio de Alfa']);

        $this->assertSame('Asunto propio de Alfa', PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL, $this->alfa->id)->asunto);
        $this->assertNotSame('Asunto propio de Alfa', PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL, $this->beta->id)->asunto);
        $this->assertNotSame('Asunto propio de Alfa', PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL)->asunto);
    }
}

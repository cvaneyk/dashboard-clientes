<?php

namespace Tests\Feature;

use App\Filament\Resources\Lineas\Pages\ListLineas;
use App\Models\Cliente;
use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\Sync\MetaConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presupuesto de plataforma: el tope de gasto que tiene la campaña EN META,
 * guardado como referencia interna para el account manager.
 *
 * Lo que se factura al cliente sale del porcentaje de presupuesto_eventos y
 * se decide a mano: estos tests fijan precisamente que lo de Meta no se cuela
 * ahí ni llega al dashboard.
 */
class PresupuestoPlataformaTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaMeta(): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'external_id'   => '123',
            'activo'        => true,
        ]);

        ConexionApi::create([
            'cuenta_id'    => $cuenta->id,
            'metodo'       => 'system_user',
            'access_token' => 'token-largo',
        ]);

        return $cuenta;
    }

    private function linea(Cuenta $cuenta, string $nombre): Linea
    {
        return Linea::create([
            'cliente_id' => $cuenta->cliente_id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => $nombre,
            'match_key'  => Linea::normalizar($nombre),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);
    }

    /** Respuestas de Graph: insights vacíos, campañas y adsets a la carta. */
    private function fakeGraph(array $campañas, array $adsets = []): void
    {
        Http::fake(function ($request) use ($campañas, $adsets) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/campaigns') => Http::response(['data' => $campañas]),
                str_contains($url, '/adsets')    => Http::response(['data' => $adsets]),
                default                          => Http::response(['data' => []]), // insights
            };
        });
    }

    public function test_guarda_el_presupuesto_diario_de_la_campana_en_euros(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, '🟥 INFANTIL_LEADADS');

        // Graph devuelve los importes en céntimos.
        $this->fakeGraph([
            ['id' => '1', 'name' => '🟥 INFANTIL_LEADADS', 'daily_budget' => '4520'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $linea->refresh();
        $this->assertEquals(45.20, $linea->presupuesto_plataforma);
        $this->assertSame('diario', $linea->presupuesto_plataforma_tipo);
        $this->assertSame('campania', $linea->presupuesto_plataforma_nivel);
        $this->assertNotNull($linea->presupuesto_plataforma_actualizado_en);
    }

    public function test_usa_el_presupuesto_de_periodo_cuando_no_hay_diario(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'GRADOS_TRAFICO');

        $this->fakeGraph([
            ['id' => '1', 'name' => 'GRADOS_TRAFICO', 'lifetime_budget' => '150000'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $linea->refresh();
        $this->assertEquals(1500.00, $linea->presupuesto_plataforma);
        $this->assertSame('total', $linea->presupuesto_plataforma_tipo);
    }

    public function test_sin_cbo_suma_el_presupuesto_de_los_adsets(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'MASTER_LEADADS');

        // La campaña no tiene presupuesto propio: está repartido en sus adsets.
        $this->fakeGraph(
            campañas: [['id' => '77', 'name' => 'MASTER_LEADADS']],
            adsets: [
                ['campaign_id' => '77', 'daily_budget' => '1000'],
                ['campaign_id' => '77', 'daily_budget' => '2500'],
                ['campaign_id' => '99', 'daily_budget' => '9999'], // otra campaña
            ]
        );

        app(MetaConnector::class)->sincronizar($cuenta);

        $linea->refresh();
        $this->assertEquals(35.00, $linea->presupuesto_plataforma);
        $this->assertSame('adsets', $linea->presupuesto_plataforma_nivel, 'Debe quedar claro que es una suma');
    }

    public function test_no_toca_el_presupuesto_que_se_factura_al_cliente(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'INFANTIL_LEADADS');

        $this->fakeGraph([
            ['id' => '1', 'name' => 'INFANTIL_LEADADS', 'daily_budget' => '4520'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(
            0,
            PresupuestoEvento::count(),
            'El tope de Meta NUNCA puede convertirse en objetivo facturable por su cuenta'
        );
    }

    public function test_si_falla_la_lectura_de_presupuestos_la_sincronizacion_sigue_valiendo(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'INFANTIL_LEADADS');

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/campaigns') || str_contains($url, '/adsets')) {
                return Http::response(['error' => ['message' => 'Rate limit']], 429);
            }

            return Http::response(['data' => [[
                'date_start'    => now()->toDateString(),
                'campaign_name' => 'INFANTIL_LEADADS',
                'impressions'   => '1000',
                'clicks'        => '50',
                'spend'         => '20.00',
            ]]]);
        });

        $res = app(MetaConnector::class)->sincronizar($cuenta);

        // Las métricas, que es lo importante, han entrado igual.
        $this->assertSame(1, $res['filas']);
        $this->assertNull($linea->refresh()->presupuesto_plataforma);
        $this->assertNull($cuenta->conexionApi->refresh()->ultimo_error, 'Un fallo del extra no marca la conexión como rota');
    }

    public function test_una_campana_que_no_existe_en_el_portal_no_crea_lineas(): void
    {
        $cuenta = $this->cuentaMeta();

        $this->fakeGraph([
            ['id' => '1', 'name' => 'CAMPAÑA_QUE_NO_ESTA', 'daily_budget' => '1000'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(0, Linea::count(), 'Los presupuestos solo anotan; las líneas las crean las métricas');
    }

    public function test_el_listado_de_lineas_marca_las_que_no_tienen_presupuesto(): void
    {
        $cuenta = $this->cuentaMeta();
        $sin    = $this->linea($cuenta, 'SIN_PRESUPUESTO');
        $con    = $this->linea($cuenta, 'CON_PRESUPUESTO');

        PresupuestoEvento::create([
            'linea_id'       => $con->id,
            'desde'          => now()->startOfMonth()->toDateString(),
            'porcentaje'     => 20,
            'estado'         => 'activa',
        ]);

        $this->assertNull($sin->eventoVigente());
        $this->assertEquals(20, $con->eventoVigente()->porcentaje);

        $admin = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/lineas')
            ->assertSuccessful()
            ->assertSee('Sin margen');
    }

    public function test_la_accion_masiva_asigna_el_objetivo_a_varias_lineas(): void
    {
        $cuenta = $this->cuentaMeta();
        $una    = $this->linea($cuenta, 'INFANTIL_LEADADS');
        $otra   = $this->linea($cuenta, 'GRADOS_TRAFICO');

        $admin = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin');

        Livewire::test(ListLineas::class)
            ->callTableBulkAction('asignar_objetivo', [$una, $otra], [
                'desde'          => now()->startOfMonth()->toDateString(),
                'porcentaje'     => 20,
                'estado'         => 'activa',
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2, PresupuestoEvento::count());
        $this->assertEquals(20, $una->refresh()->eventoVigente()->porcentaje);
        $this->assertEquals(20, $otra->refresh()->eventoVigente()->porcentaje);

        // Repetirla con la misma fecha corrige el evento, no lo duplica.
        Livewire::test(ListLineas::class)
            ->callTableBulkAction('asignar_objetivo', [$una], [
                'desde'          => now()->startOfMonth()->toDateString(),
                'porcentaje'     => 35,
                'estado'         => 'activa',
            ]);

        $this->assertSame(2, PresupuestoEvento::count());
        $this->assertEquals(35, $una->refresh()->eventoVigente()->porcentaje);
    }

    public function test_un_evento_futuro_no_cuenta_como_presupuesto_vigente(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'ARRANCA_EL_MES_QUE_VIENE');

        PresupuestoEvento::create([
            'linea_id'       => $linea->id,
            'desde'          => now()->addMonth()->startOfMonth()->toDateString(),
            'porcentaje'     => 30,
            'estado'         => 'activa',
        ]);

        $this->assertNull($linea->eventoVigente(), 'Aún no rige: hoy esa línea sigue sin presupuesto');
    }

    public function test_eventoVigente_coge_el_ultimo_cambio_aplicable(): void
    {
        $cuenta = $this->cuentaMeta();
        $linea  = $this->linea($cuenta, 'SUBE_A_MITAD_DE_MES');

        foreach ([[now()->startOfMonth(), 1000], [now()->subDays(2), 1600]] as [$fecha, $importe]) {
            PresupuestoEvento::create([
                'linea_id'       => $linea->id,
                'desde'          => $fecha->toDateString(),
                'porcentaje'     => $importe,
                'estado'         => 'activa',
            ]);
        }

        $this->assertEquals(1600, $linea->eventoVigente()->porcentaje);
    }
}

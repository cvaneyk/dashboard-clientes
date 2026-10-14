<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\Sync\OpenAiAdsConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de OpenAI Ads. Todas las llamadas van fakeadas.
 *
 * Lo propio de OpenAI: una clave de API por cuenta (sin OAuth), un único
 * informe que ya trae nombre y día, paginación por cursor, métricas a null en
 * vez de 0 y rangos que no pueden acabar en el futuro.
 */
class OpenAiAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cuentaOpenai(string $externalId = 'adacct_123'): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'openai',
            'nombre_cuenta' => 'Mi universidad · ChatGPT',
            'external_id'   => $externalId,
            'activo'        => true,
        ]);
    }

    private function conexion(Cuenta $cuenta): ConexionApi
    {
        return ConexionApi::create([
            'cuenta_id'    => $cuenta->id,
            'metodo'       => 'api_key',
            'access_token' => 'sk-ads-cliente',
        ]);
    }

    /** Una fila de insights tal como la devuelve OpenAI. */
    private function fila(string $nombre, string $fecha, array $extras = []): array
    {
        return array_merge([
            'id'            => "start=1:end=2:entity_id=cmpn_101:{$fecha}",
            'readable_time' => $fecha,
            'campaign_id'   => 'cmpn_101',
            'campaign_name' => $nombre,
            'impressions'   => 1200,
            'clicks'        => 36,
            'spend'         => 18.42,
            'conversions'   => 3,
        ], $extras);
    }

    private function fakeOpenai(array $filas, array $campanas = [], array $cuenta = ['id' => 'adacct_123', 'name' => 'Mi universidad']): void
    {
        Http::fake(function ($request) use ($filas, $campanas, $cuenta) {
            $url = $request->url();

            if (str_contains($url, '/ad_account/insights')) {
                return Http::response(['object' => 'list', 'data' => $filas, 'has_more' => false]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['object' => 'list', 'data' => $campanas, 'has_more' => false]);
            }
            if (str_contains($url, '/ad_account')) {
                return Http::response($cuenta);
            }

            return Http::response([]);
        });
    }

    public function test_importa_el_informe_diario_por_campana(): void
    {
        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);
        $this->fakeOpenai([$this->fila('ChatGPT - Grados', '2026-04-01')]);

        $res = app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(1, $res['filas']);
        $this->assertSame('chatgpt - grados', Linea::sole()->match_key);

        $dia = MetricaDiaria::sole();
        $this->assertSame(1200, $dia->impresiones);
        $this->assertSame(36, $dia->clics);
        $this->assertEquals(18.42, $dia->gasto_real, 'El gasto viene en la moneda, no en micros');
        $this->assertEquals(3, $dia->leads);
        $this->assertSame(0, $dia->alcance);

        $this->assertStringContainsString('OpenAI Ads API', ImportLog::find($res['import_id'])->fichero);
    }

    public function test_las_metricas_a_null_cuentan_como_cero(): void
    {
        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);
        $this->fakeOpenai([$this->fila('CAMPAÑA', '2026-04-01', ['spend' => null, 'conversions' => null])]);

        app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $dia = MetricaDiaria::sole();
        $this->assertEquals(0, $dia->gasto_real);
        $this->assertEquals(0, $dia->leads);
    }

    public function test_pide_insights_por_campana_y_dia_con_la_clave_de_la_cuenta(): void
    {
        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);
        $this->fakeOpenai([]);

        app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-07');

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            if (! str_contains($url, 'insights')) {
                return true;
            }

            return str_contains($url, 'https://api.ads.openai.com/v1/ad_account/insights?')
                && str_contains($url, 'aggregation_level=campaign')
                && str_contains($url, 'time_granularity=daily')
                && str_contains($url, '"since":"2026-04-01","until":"2026-04-07"')
                && str_contains($url, 'fields[]=campaign_name&fields[]=readable_time')
                && $request->header('Authorization')[0] === 'Bearer sk-ads-cliente';
        });
    }

    public function test_sigue_la_paginacion_por_cursor(): void
    {
        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);

        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'insights')) {
                return Http::response(['data' => [], 'has_more' => false]);
            }

            return str_contains($request->url(), 'after=fila-1')
                ? Http::response(['data' => [$this->fila('B', '2026-04-01')], 'has_more' => false, 'last_id' => 'fila-2'])
                : Http::response(['data' => [$this->fila('A', '2026-04-01')], 'has_more' => true, 'last_id' => 'fila-1']);
        });

        $res = app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(2, $res['filas']);
    }

    /** "Hasta hoy" acabaría a medianoche de mañana, que OpenAI rechaza por futuro. */
    public function test_no_pide_el_dia_en_curso(): void
    {
        Carbon::setTestNow('2026-04-10 12:00:00');

        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);
        $this->fakeOpenai([]);

        app(OpenAiAdsConnector::class)->sincronizar($cuenta, 3);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'insights')
            || str_contains(urldecode($request->url()), '"since":"2026-04-07","until":"2026-04-09"'));
    }

    public function test_guarda_el_limite_de_gasto_sin_tocar_lo_facturable(): void
    {
        $cuenta = $this->cuentaOpenai();
        $this->conexion($cuenta);
        $this->fakeOpenai(
            [$this->fila('ChatGPT - Grados', '2026-04-01')],
            [['id' => 'cmpn_101', 'name' => 'ChatGPT - Grados', 'budget' => ['lifetime_spend_limit_micros' => 250000000]]]
        );

        app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $linea = Linea::sole();
        $this->assertEquals(250.00, $linea->presupuesto_plataforma, 'Micros → euros');
        $this->assertSame('total', $linea->presupuesto_plataforma_tipo);
        $this->assertSame(0, PresupuestoEvento::count());
    }

    public function test_guardar_la_clave_comprueba_que_es_de_esta_cuenta(): void
    {
        $cuenta = $this->cuentaOpenai('adacct_123');
        $this->fakeOpenai([], [], ['id' => 'adacct_999', 'name' => 'Otro cliente']);

        try {
            app(OpenAiAdsConnector::class)->guardarApiKey($cuenta, 'sk-de-otro');
            $this->fail('Una clave de otra cuenta no se puede guardar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('adacct_999', $e->getMessage());
        }

        $this->assertSame(0, ConexionApi::count());
    }

    public function test_guardar_la_clave_rellena_el_id_si_faltaba(): void
    {
        $cuenta = $this->cuentaOpenai('');
        $this->fakeOpenai([]);

        $conexion = app(OpenAiAdsConnector::class)->guardarApiKey($cuenta, '  sk-buena  ');

        $this->assertSame('api_key', $conexion->metodo);
        $this->assertSame('sk-buena', $conexion->access_token);
        $this->assertSame('adacct_123', $cuenta->refresh()->external_id);
    }

    public function test_una_clave_invalida_dice_que_hacer(): void
    {
        $cuenta   = $this->cuentaOpenai();
        $conexion = $this->conexion($cuenta);

        Http::fake(fn () => Http::response(['error' => ['message' => 'Invalid API key']], 401));

        try {
            app(OpenAiAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');
            $this->fail('Debe lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
            $this->assertStringContainsString('Settings', $e->getMessage());
        }

        $this->assertStringContainsString('Invalid API key', (string) $conexion->refresh()->ultimo_error);
    }

    public function test_la_ficha_de_una_cuenta_de_openai_renderiza(): void
    {
        $cuenta = $this->cuentaOpenai();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/cuentas/' . $cuenta->id . '/edit')
            ->assertSuccessful()
            ->assertSee('Conectar con clave de API de OpenAI');
    }

    public function test_el_comando_de_sincronizacion_incluye_openai(): void
    {
        $cuenta = $this->cuentaOpenai();

        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('no tiene conexión con la API')
            ->assertFailed();
    }
}

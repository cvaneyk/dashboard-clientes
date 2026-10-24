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
use App\Services\Sync\GoogleAdsConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de Google Ads. Todas las llamadas van fakeadas.
 *
 * Lo que de verdad se comprueba aquí es lo que distingue a Google de Meta: el
 * doble escalón de tokens (refresh eterno → access de una hora), los importes
 * en micros, el customer_id con guiones y la cabecera login-customer-id.
 */
class GoogleAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_ads.client_id'         => 'cliente.apps.googleusercontent.com',
            'services.google_ads.client_secret'     => 'secreto',
            'services.google_ads.login_customer_id' => '999-888-7777',
            'services.google_ads.api_version'       => 'v25',
        ]);
    }

    private function cuentaGoogle(string $externalId = '123-456-7890'): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'google',
            'nombre_cuenta' => 'Mi universidad · Google',
            'external_id'   => $externalId,
            'activo'        => true,
        ]);
    }

    private function conexion(Cuenta $cuenta, array $extras = []): ConexionApi
    {
        return ConexionApi::create(array_merge([
            'cuenta_id'     => $cuenta->id,
            'metodo'        => 'mcc',
            'access_token'  => 'access-vigente',
            'refresh_token' => 'refresh-eterno',
            'expires_at'    => now()->addHour(),
        ], $extras));
    }

    /** Una fila de googleAds:search tal como la devuelve la API REST. */
    private function fila(string $campana, string $fecha, array $metrics = []): array
    {
        return [
            'campaign' => ['name' => $campana],
            'segments' => ['date' => $fecha],
            'metrics'  => array_merge([
                'impressions' => '1529',
                'clicks'      => '57',
                'costMicros'  => '45200000', // 45,20 €
                'conversions' => 3,
            ], $metrics),
        ];
    }

    private function fakeGoogle(array $resultados, array $presupuestos = []): void
    {
        Http::fake(function ($request) use ($resultados, $presupuestos) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'access-nuevo', 'expires_in' => 3600]);
            }

            $cuerpo = json_encode($request->data());

            // La consulta de presupuestos se distingue por su GAQL.
            if (str_contains((string) $cuerpo, 'campaign_budget')) {
                return Http::response(['results' => $presupuestos]);
            }

            return Http::response(['results' => $resultados]);
        });
    }

    public function test_mapea_las_metricas_y_convierte_los_micros_a_euros(): void
    {
        $cuenta   = $this->cuentaGoogle();
        $conexion = $this->conexion($cuenta);

        $this->fakeGoogle([
            $this->fila('GRADOS - BUSQUEDA', '2026-04-01'),
            $this->fila('GRADOS - BUSQUEDA', '2026-04-02', ['clicks' => '83', 'costMicros' => '61750000', 'conversions' => 5]),
        ]);

        $res = app(GoogleAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-02');

        $this->assertSame(2, $res['filas']);
        $this->assertSame(1, $res['lineas_nuevas']);

        $dia = MetricaDiaria::where('fecha', '2026-04-01')->sole();
        $this->assertSame(1529, $dia->impresiones);
        $this->assertSame(57, $dia->clics);
        $this->assertEquals(45.20, $dia->gasto_real, 'costMicros / 1.000.000');
        $this->assertEquals(3, $dia->leads, 'Las conversiones de Google son los leads del portal');
        $this->assertSame(0, $dia->alcance, 'Google Ads no da alcance en este informe');

        $log = ImportLog::find($res['import_id']);
        $this->assertSame('api', $log->origen);
        $this->assertStringContainsString('Google Ads API', $log->fichero);
    }

    public function test_manda_login_customer_id_sin_guiones_y_ya_no_el_developer_token(): void
    {
        $cuenta = $this->cuentaGoogle('123-456-7890');
        $this->conexion($cuenta);
        $this->fakeGoogle([]);

        app(GoogleAdsConnector::class)->sincronizar($cuenta);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'googleads.googleapis.com')) {
                return true;
            }

            return ! $request->hasHeader('developer-token')
                && $request->header('login-customer-id')[0] === '9998887777'
                && str_contains($request->url(), '/customers/1234567890/googleAds:search');
        });
    }

    public function test_oauth_del_cliente_no_hereda_la_mcc_de_la_agencia(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta, ['metodo' => 'oauth']);
        $this->fakeGoogle([]);

        app(GoogleAdsConnector::class)->sincronizar($cuenta);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'googleads.googleapis.com')
            || ! $request->hasHeader('login-customer-id'));
    }

    public function test_la_cuenta_puede_tener_su_propia_mcc(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta, ['login_customer_id' => '111-222-3333']);
        $this->fakeGoogle([]);

        app(GoogleAdsConnector::class)->sincronizar($cuenta);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'googleads.googleapis.com')
            || $request->header('login-customer-id')[0] === '1112223333');
    }

    public function test_renueva_el_access_token_caducado_con_el_refresh(): void
    {
        $cuenta   = $this->cuentaGoogle();
        $conexion = $this->conexion($cuenta, ['access_token' => 'access-viejo', 'expires_at' => now()->subMinute()]);

        $this->fakeGoogle([]);

        app(GoogleAdsConnector::class)->sincronizar($cuenta);

        $conexion->refresh();
        $this->assertSame('access-nuevo', $conexion->access_token);
        $this->assertTrue($conexion->expires_at->isFuture());

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'oauth2.googleapis.com')
            || $request->data()['grant_type'] === 'refresh_token');
    }

    public function test_no_pide_token_nuevo_si_el_que_hay_sigue_vigente(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta, ['expires_at' => now()->addHour()]);

        $renovaciones = 0;
        Http::fake(function ($request) use (&$renovaciones) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                $renovaciones++;

                return Http::response(['access_token' => 'access-nuevo', 'expires_in' => 3600]);
            }

            return Http::response(['results' => []]);
        });

        app(GoogleAdsConnector::class)->sincronizar($cuenta);

        $this->assertSame(0, $renovaciones);
    }

    public function test_sigue_la_paginacion(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'access-nuevo', 'expires_in' => 3600]);
            }

            if (($request->data()['pageToken'] ?? null) === 'pagina2') {
                return Http::response(['results' => [$this->fila('CAMP_B', '2026-04-01')]]);
            }

            return Http::response([
                'results'       => [$this->fila('CAMP_A', '2026-04-01')],
                'nextPageToken' => 'pagina2',
            ]);
        });

        $res = app(GoogleAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(2, $res['filas']);
        $this->assertSame(2, Linea::count());
    }

    public function test_guarda_el_presupuesto_como_referencia_sin_tocar_lo_facturable(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta);

        $this->fakeGoogle(
            resultados: [$this->fila('GRADOS - BUSQUEDA', '2026-04-01')],
            presupuestos: [[
                'campaign'       => ['name' => 'GRADOS - BUSQUEDA'],
                'campaignBudget' => ['amountMicros' => '30000000', 'period' => 'DAILY'],
            ]]
        );

        app(GoogleAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $linea = Linea::sole();
        $this->assertEquals(30.00, $linea->presupuesto_plataforma);
        $this->assertSame('diario', $linea->presupuesto_plataforma_tipo);
        $this->assertSame(0, PresupuestoEvento::count(), 'El presupuesto de Google no es el objetivo del cliente');
    }

    public function test_un_error_de_google_se_explica_y_queda_en_la_conexion(): void
    {
        $cuenta   = $this->cuentaGoogle();
        $conexion = $this->conexion($cuenta);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'access-nuevo', 'expires_in' => 3600]);
            }

            // Google esconde el motivo real dentro de error.details.
            return Http::response([
                'error' => [
                    'message' => 'Request contains an invalid argument.',
                    'details' => [[
                        'errors' => [[
                            'message' => 'The developer token is not approved for this customer.',
                        ]],
                    ]],
                ],
            ], 400);
        });

        try {
            app(GoogleAdsConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');
            $this->fail('Debe lanzar para que el comando o el botón avisen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('developer token is not approved', $e->getMessage());
        }

        $this->assertStringContainsString('developer token', (string) $conexion->refresh()->ultimo_error);
        $this->assertSame(0, MetricaDiaria::count());
    }

    public function test_falta_el_customer_id_y_se_rechaza_claramente(): void
    {
        $cuenta = $this->cuentaGoogle('');
        $this->conexion($cuenta);
        $this->fakeGoogle([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('external_id');

        app(GoogleAdsConnector::class)->sincronizar($cuenta);
    }

    public function test_conectar_con_la_mcc_guarda_el_refresh_token_cifrado(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->fakeGoogle([]);

        $conexion = app(GoogleAdsConnector::class)->guardarRefreshToken($cuenta, 'refresh-de-la-mcc', '123-456-7890');

        $this->assertSame('mcc', $conexion->metodo);
        $this->assertSame('refresh-de-la-mcc', $conexion->refresh_token);
        $this->assertSame('1234567890', $conexion->login_customer_id);

        // Cifrado en la base de datos, legible por el modelo.
        $crudo = \DB::table('conexiones_api')->where('id', $conexion->id)->value('refresh_token');
        $this->assertNotSame('refresh-de-la-mcc', $crudo);
    }

    public function test_el_oauth_exige_refresh_token(): void
    {
        $cuenta = $this->cuentaGoogle();

        // Google omite refresh_token si esa cuenta ya había autorizado antes.
        Http::fake(fn () => Http::response(['access_token' => 'access', 'expires_in' => 3600]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refresh token');

        app(GoogleAdsConnector::class)->guardarTokenOauth($cuenta, 'CODIGO', 'https://portal.example.com/conexiones/google/callback');
    }

    public function test_el_callback_con_state_falsificado_no_conecta(): void
    {
        $cuenta = $this->cuentaGoogle();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['conexiones.google' => ['nonce' => 'nonceseguro', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/google/callback?state=intruso&code=ELCODE');

        $this->assertSame(0, ConexionApi::count());
    }

    /** Renderiza la ficha con las acciones de Google: caza errores de cableado. */
    public function test_la_ficha_de_una_cuenta_de_google_renderiza(): void
    {
        $cuenta = $this->cuentaGoogle();
        $this->conexion($cuenta);
        $admin = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/cuentas/' . $cuenta->id . '/edit')
            ->assertSuccessful()
            ->assertSee('MCC');
    }
}

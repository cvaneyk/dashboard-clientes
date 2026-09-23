<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\MetricaDiaria;
use App\Models\User;
use App\Services\Sync\MetaConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de la Marketing API de Meta: canje del token OAuth, mapeo de
 * insights a filas normalizadas, renovación de token y el cableado del
 * callback HTTP. Todas las llamadas a Graph van fakeadas.
 */
class MetaConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaMeta(string $externalId = '123'): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'external_id'   => $externalId,
            'activo'        => true,
        ]);
    }

    /** @param  array<string,mixed>  $extras */
    private function conexion(Cuenta $cuenta, string $metodo = 'system_user', array $extras = []): ConexionApi
    {
        return ConexionApi::create(array_merge([
            'cuenta_id'    => $cuenta->id,
            'metodo'       => $metodo,
            'access_token' => 'token-largo',
        ], $extras));
    }

    /** @return array<string,mixed> Fila de insights tal como la devuelve Graph. */
    private function insight(string $campaña, string $fecha, array $extras = []): array
    {
        return array_merge([
            'date_start'    => $fecha,
            'date_stop'     => $fecha,
            'campaign_name' => $campaña,
            'impressions'   => '1529',
            'reach'         => '980',
            'clicks'        => '57',
            'spend'         => '45.20',
            'actions'       => [
                ['action_type' => 'link_click', 'value' => '40'],
                ['action_type' => 'lead', 'value' => '3'],
            ],
        ], $extras);
    }

    public function test_canjea_el_code_y_guarda_token_de_vida_larga(): void
    {
        config(['services.meta.app_id' => 'app', 'services.meta.app_secret' => 'secreto']);
        $cuenta = $this->cuentaMeta();

        Http::fake(function ($request) {
            $url = $request->url();
            return match (true) {
                str_contains($url, 'code=CODIGO')                 => Http::response(['access_token' => 'token-corto']),
                str_contains($url, 'fb_exchange_token=token-corto') => Http::response(['access_token' => 'token-largo', 'expires_in' => 5184000]),
                str_contains($url, 'act_123')                       => Http::response(['id' => 'act_123', 'name' => 'Cuenta de anuncios']),
                default => Http::response([], 404),
            };
        });

        $conexion = app(MetaConnector::class)->guardarTokenOauth($cuenta, 'CODIGO', 'https://clientes.olimedia.es/conexiones/meta/callback');

        $this->assertSame('oauth', $conexion->metodo);
        $this->assertSame('token-largo', $conexion->access_token, 'El token se guarda cifrado y se lee en claro');
        $this->assertEqualsWithDelta(60, abs($conexion->expires_at->diffInDays(now())), 1);
        $this->assertSame(1, ConexionApi::count(), 'Una conexión por cuenta, no una por canje');
    }

    public function test_el_oauth_rechaza_si_el_usuario_no_ve_la_cuenta(): void
    {
        config(['services.meta.app_id' => 'app', 'services.meta.app_secret' => 'secreto']);
        $cuenta = $this->cuentaMeta();

        Http::fake(function ($request) {
            $url = $request->url();
            return match (true) {
                str_contains($url, 'code=CODIGO')                  => Http::response(['access_token' => 'token-corto']),
                str_contains($url, 'fb_exchange_token=token-corto') => Http::response(['access_token' => 'token-largo', 'expires_in' => 5184000]),
                str_contains($url, 'act_123')                       => Http::response([
                    'error' => ['message' => 'Permissions error', 'code' => 200],
                ], 403),
                default => Http::response([], 404),
            };
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permissions error');

        // Sin acceso a act_123: el guardado entero debe fallar sin dejar conexión.
        app(MetaConnector::class)->guardarTokenOauth($cuenta, 'CODIGO', 'https://clientes.olimedia.es/conexiones/meta/callback');
    }

    public function test_sincronizar_mapea_los_insights_a_metricas_diarias(): void
    {
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    $this->insight('🟥 INFANTIL_LEADADS', now()->toDateString()),
                    $this->insight('🟥 INFANTIL_LEADADS', now()->subDay()->toDateString(), ['clicks' => '83', 'spend' => '61.75', 'actions' => [['action_type' => 'lead', 'value' => '5']]]),
                    $this->insight('GRADOS_TRÁFICO', now()->toDateString(), ['impressions' => '0', 'reach' => '0', 'clicks' => '0', 'spend' => '0', 'actions' => []]),
                ],
            ]),
        ]);

        $res = app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(3, $res['filas']);
        $this->assertSame(0, $res['filas_error']);
        $this->assertSame(3, MetricaDiaria::count());

        $dia = MetricaDiaria::where('fecha', now()->toDateString())->where('match_key', 'infantil_leadads')->sole();
        $this->assertSame(1529, $dia->impresiones);
        $this->assertSame(980, $dia->alcance);
        $this->assertSame(57, $dia->clics);
        $this->assertEquals(45.20, $dia->gasto_real);
        $this->assertEquals(3, $dia->leads, 'El lead cuenta solo con action_type=lead, no otros eventos');

        // El log deja claro que esto salió de la API, no de un Excel subido.
        $log = ImportLog::find($res['import_id']);
        $this->assertSame('api', $log->origen);
        $this->assertStringContainsString('Meta API', $log->fichero);
        $this->assertNull($log->user_id, 'Sincronización automática: sin usuario');

        // La conexión queda marcada como sincronizada y limpia de errores.
        $conexion->refresh();
        $this->assertNotNull($conexion->ultima_sincronizacion);
        $this->assertNull($conexion->ultimo_error);
    }

    public function test_resincronizar_no_duplica(): void
    {
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [$this->insight('🟥 INFANTIL_LEADADS', now()->toDateString())],
            ]),
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);
        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(1, MetricaDiaria::count());
        $this->assertSame(2, ImportLog::count(), 'Cada sincronización deja su log de auditoría');
    }

    public function test_sigue_la_paginacion_de_graph(): void
    {
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'offset=500')) {
                return Http::response(['data' => [$this->insight('CAMP_B', now()->toDateString())]]);
            }

            return Http::response([
                'data'   => [$this->insight('CAMP_A', now()->toDateString())],
                'paging' => ['next' => 'https://graph.facebook.com/v23.0/act_123/insights?offset=500&limit=500'],
            ]);
        });

        $res = app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(2, $res['filas']);
        $this->assertSame(2, MetricaDiaria::count());
    }

    public function test_renueva_el_token_oauth_cuando_esta_cerca_de_caducar(): void
    {
        config(['services.meta.app_id' => 'app', 'services.meta.app_secret' => 'secreto']);
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta, 'oauth', ['expires_at' => now()->addDays(2)]);

        Http::fake(function ($request) {
            $url = $request->url();
            return match (true) {
                str_contains($url, 'fb_exchange_token=token-largo') => Http::response(['access_token' => 'token-nuevo', 'expires_in' => 5184000]),
                str_contains($url, '/insights')                       => Http::response(['data' => []]),
                default => Http::response([], 404),
            };
        });

        app(MetaConnector::class)->sincronizar($cuenta);

        $conexion->refresh();
        $this->assertSame('token-nuevo', $conexion->access_token);
        $this->assertEqualsWithDelta(60, abs($conexion->expires_at->diffInDays(now())), 1);
    }

    public function test_no_renueva_los_tokens_que_no_caducan(): void
    {
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta, 'system_user'); // expires_at = null

        $canjes = 0;
        Http::fake(function ($request) use (&$canjes) {
            if (str_contains($request->url(), 'fb_exchange_token')) {
                $canjes++;
            }

            return Http::response(['data' => []]);
        });

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertSame(0, $canjes, 'El token de System User no se toca');
        $conexion->refresh();
        $this->assertSame('token-largo', $conexion->access_token);
    }

    public function test_un_error_de_meta_queda_registrado_en_la_conexion(): void
    {
        $cuenta   = $this->cuentaMeta();
        $conexion = $this->conexion($cuenta);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Error validating access token: session has expired'],
            ], 401),
        ]);

        try {
            app(MetaConnector::class)->sincronizar($cuenta);
            $this->fail('Debe lanzar para que el comando o el botón avisen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('session has expired', $e->getMessage());
        }

        $conexion->refresh();
        $this->assertStringContainsString('session has expired', (string) $conexion->ultimo_error);
        $this->assertSame(0, MetricaDiaria::count());
    }

    public function test_falta_el_external_id_y_se_rechaza_claramente(): void
    {
        $cuenta   = $this->cuentaMeta('');
        $conexion = $this->conexion($cuenta);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('external_id');

        app(MetaConnector::class)->sincronizar($cuenta);
    }

    public function test_callback_oauth_conecta_y_vuelve_a_la_ficha(): void
    {
        config(['services.meta.app_id' => 'app', 'services.meta.app_secret' => 'secreto']);
        $cuenta = $this->cuentaMeta();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            return match (true) {
                str_contains($url, 'code=ELCODE')                  => Http::response(['access_token' => 'token-corto']),
                str_contains($url, 'fb_exchange_token=token-corto') => Http::response(['access_token' => 'token-largo', 'expires_in' => 5184000]),
                str_contains($url, 'act_123')                       => Http::response(['id' => 'act_123', 'name' => 'Cuenta de anuncios']),
                default => Http::response([], 404),
            };
        });

        $respuesta = $this->actingAs($admin, 'admin')
            ->withSession(['conexiones.meta' => ['nonce' => 'nonceseguro', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/meta/callback?state=nonceseguro&code=ELCODE');

        $destino = $respuesta->headers->get('Location');
        $this->assertStringContainsString('/admin/cuentas/' . $cuenta->id . '/edit', (string) $destino);
        $this->assertStringContainsString('conexion=ok', (string) $destino);
        $this->assertSame('oauth', $cuenta->conexionApi()->first()->metodo);
    }

    public function test_callback_con_state_falsificado_no_conecta(): void
    {
        $cuenta = $this->cuentaMeta();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['conexiones.meta' => ['nonce' => 'nonceseguro', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/meta/callback?state=intruso&code=ELCODE');

        $this->assertSame(0, ConexionApi::count());
    }

    /** Renderiza la ficha con las acciones de conexión: captura errores de cableado de Filament. */
    public function test_la_ficha_de_cuenta_renderiza_con_las_acciones_de_conexion(): void
    {
        $cuenta = $this->cuentaMeta();
        $this->conexion($cuenta);
        $admin = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/cuentas/' . $cuenta->id . '/edit')
            ->assertSuccessful();
    }
}

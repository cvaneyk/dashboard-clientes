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
use App\Services\Sync\SpotifyConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de Spotify Ads. Todas las llamadas van fakeadas.
 *
 * Lo propio de Spotify: las métricas llegan como lista de pares
 * {field_type, field_value}, el rango diario no puede pasar de 90 días, la
 * paginación va con continuation_token y las conversiones pequeñas llegan
 * enmascaradas como -5.
 */
class SpotifyConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA = 'ce4ff15e-f04d-48b9-9ddf-fb3c85fbd57a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.spotify_ads.client_id'        => 'cliente',
            'services.spotify_ads.client_secret'    => 'secreto',
            'services.spotify_ads.tipos_conversion' => ['LEADS', 'SIGN_UPS'],
        ]);
    }

    private function cuentaSpotify(?string $tipos = null, string $externalId = self::CUENTA): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'       => $cliente->id,
            'plataforma'       => 'spotify',
            'nombre_cuenta'    => 'Mi universidad · Spotify',
            'external_id'      => $externalId,
            'tipos_conversion' => $tipos,
            'activo'           => true,
        ]);
    }

    private function conexion(Cuenta $cuenta, array $extras = []): ConexionApi
    {
        return ConexionApi::create(array_merge([
            'cuenta_id'     => $cuenta->id,
            'metodo'        => 'oauth',
            'access_token'  => 'access-vigente',
            'refresh_token' => 'refresh-eterno',
            'expires_at'    => now()->addMinutes(50),
        ], $extras));
    }

    /** Una fila del informe agregado tal como la devuelve Spotify. */
    private function fila(string $nombre, string $fecha, array $stats = []): array
    {
        $stats = array_merge([
            'IMPRESSIONS' => 1920,
            'REACH'       => 1500,
            'CLICKS'      => 9,
            'SPEND'       => 17.482913,
            'LEADS'       => 6,
            'SIGN_UPS'    => 11,
        ], $stats);

        return [
            'entity_type'   => 'CAMPAIGN',
            'entity_id'     => '7f4c1cc9-9a1d-4b65-b05c-46e5e33b6705',
            'entity_name'   => $nombre,
            'entity_status' => 'ACTIVE',
            'start_time'    => $fecha . 'T00:00:00Z',
            'end_time'      => $fecha . 'T00:00:00Z',
            'stats'         => array_map(
                fn ($campo, $valor) => ['field_type' => $campo, 'field_value' => $valor],
                array_keys($stats),
                $stats
            ),
        ];
    }

    private function fakeSpotify(array $filas, array $campanas = [], array $adSets = []): void
    {
        Http::fake(function ($request) use ($filas, $campanas, $adSets) {
            $url = $request->url();

            if (str_contains($url, 'accounts.spotify.com/api/token')) {
                // El refresh token solo llega al canjear el código; al renovar
                // Spotify puede no mandarlo.
                return Http::response(array_filter([
                    'access_token'  => 'access-nuevo',
                    'expires_in'    => 3600,
                    'token_type'    => 'Bearer',
                    'refresh_token' => $request['grant_type'] === 'authorization_code' ? 'refresh-nuevo' : null,
                ]));
            }
            if (str_contains($url, '/aggregate_reports')) {
                return Http::response(['rows' => $filas, 'continuation_token' => null]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['paging' => ['total_results' => count($campanas)], 'campaigns' => $campanas]);
            }
            if (str_contains($url, '/ad_sets')) {
                return Http::response(['paging' => ['total_results' => count($adSets)], 'ad_sets' => $adSets]);
            }

            return Http::response([]);
        });
    }

    public function test_importa_el_informe_diario_por_campana(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify([$this->fila('🎧 PODCAST - GRADOS', '2026-04-01')]);

        $res = app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(1, $res['filas']);
        $this->assertSame('podcast - grados', Linea::sole()->match_key);

        $dia = MetricaDiaria::sole();
        $this->assertSame(1920, $dia->impresiones);
        $this->assertSame(1500, $dia->alcance);
        $this->assertSame(9, $dia->clics);
        $this->assertEquals(17.48, $dia->gasto_real, 'El gasto viene en la moneda, no en micros');
        $this->assertEquals('2026-04-01', $dia->fecha->toDateString());

        $this->assertStringContainsString('Spotify Ads API', ImportLog::find($res['import_id'])->fichero);
    }

    public function test_pide_el_informe_con_campos_repetidos_y_medianoche_utc(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify([]);

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-03');

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            if (! str_contains($url, 'aggregate_reports')) {
                return true;
            }

            return str_contains($url, 'api-partner.spotify.com/ads/v3/ad_accounts/' . self::CUENTA)
                && str_contains($url, 'fields=IMPRESSIONS&')
                && str_contains($url, 'fields=SPEND')
                && ! str_contains($url, 'fields[')
                && str_contains($url, 'granularity=DAY')
                && str_contains($url, 'entity_type=CAMPAIGN')
                && str_contains($url, 'report_start=2026-04-01T00:00:00Z')
                && str_contains($url, 'report_end=2026-04-03T00:00:00Z')
                && $request->header('Authorization')[0] === 'Bearer access-vigente';
        });
    }

    public function test_prioriza_el_primer_campo_de_conversion_sin_sumar(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify([$this->fila('PODCAST', '2026-04-01')]);

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(6, MetricaDiaria::sole()->leads, 'Manda LEADS; sumar daría 17');
    }

    public function test_cada_cuenta_puede_elegir_su_conversion_e_ignora_las_invalidas(): void
    {
        $cuenta = $this->cuentaSpotify('inventado, sign_ups');
        $this->conexion($cuenta);
        $this->fakeSpotify([$this->fila('PODCAST', '2026-04-01')]);

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(11, MetricaDiaria::sole()->leads);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'inventado')
            || str_contains(strtoupper($request->url()), 'INVENTADO'));
    }

    /** Spotify enmascara entre 1 y 4 conversiones como -5: no es un dato real. */
    public function test_las_conversiones_enmascaradas_no_cuentan(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify([$this->fila('PODCAST', '2026-04-01', ['LEADS' => -5, 'SIGN_UPS' => -5])]);

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(0, MetricaDiaria::sole()->leads);
    }

    public function test_trocea_los_rangos_de_mas_de_90_dias(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify([]);

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-01-01', '2026-04-30');

        $informes = collect(Http::recorded())
            ->map(fn ($par) => urldecode($par[0]->url()))
            ->filter(fn ($url) => str_contains($url, 'aggregate_reports'))
            ->values();

        $this->assertCount(2, $informes);
        $this->assertStringContainsString('report_end=2026-03-31T00:00:00Z', $informes[0]);
        $this->assertStringContainsString('report_start=2026-04-01T00:00:00Z', $informes[1]);
    }

    public function test_sigue_el_continuation_token_mandandolo_solo(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);

        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'aggregate_reports')) {
                return Http::response([]);
            }

            return str_contains($request->url(), 'continuation_token=PAG2')
                ? Http::response(['rows' => [$this->fila('B', '2026-04-01')], 'continuation_token' => null])
                : Http::response(['rows' => [$this->fila('A', '2026-04-01')], 'continuation_token' => 'PAG2']);
        });

        $res = app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(2, $res['filas']);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'continuation_token=')
            || ! str_contains($request->url(), 'fields='));
    }

    public function test_suma_el_presupuesto_de_los_ad_sets_sin_tocar_lo_facturable(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->conexion($cuenta);
        $this->fakeSpotify(
            filas: [$this->fila('PODCAST - GRADOS', '2026-04-01')],
            campanas: [['id' => 'c1', 'name' => 'PODCAST - GRADOS']],
            adSets: [
                ['campaign_id' => 'c1', 'budget' => ['micro_amount' => 15000000, 'type' => 'DAILY']],
                ['campaign_id' => 'c1', 'budget' => ['micro_amount' => 5000000, 'type' => 'DAILY']],
            ]
        );

        app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $linea = Linea::sole();
        $this->assertEquals(20.00, $linea->presupuesto_plataforma, 'Micros → euros, sumando ad sets');
        $this->assertSame('diario', $linea->presupuesto_plataforma_tipo);
        $this->assertSame('adsets', $linea->presupuesto_plataforma_nivel);
        $this->assertSame(0, PresupuestoEvento::count());
    }

    public function test_renueva_el_access_token_caducado(): void
    {
        $cuenta   = $this->cuentaSpotify();
        $conexion = $this->conexion($cuenta, ['expires_at' => now()->subMinute()]);
        $this->fakeSpotify([]);

        app(SpotifyConnector::class)->sincronizar($cuenta);

        $conexion->refresh();
        $this->assertSame('access-nuevo', $conexion->access_token);
        $this->assertSame('refresh-eterno', $conexion->refresh_token, 'Si no rota, se conserva el refresh token');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api/token')
            || ($request->header('Authorization')[0] === 'Basic ' . base64_encode('cliente:secreto')
                && $request['grant_type'] === 'refresh_token'));
    }

    public function test_un_403_explica_lo_de_los_terminos_de_la_api(): void
    {
        $cuenta   = $this->cuentaSpotify();
        $conexion = $this->conexion($cuenta);

        Http::fake(fn () => Http::response(['error' => ['status' => 403, 'message' => 'Forbidden']], 403));

        try {
            app(SpotifyConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');
            $this->fail('Debe lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Forbidden', $e->getMessage());
            $this->assertStringContainsString('api-terms', $e->getMessage());
        }

        $this->assertStringContainsString('api-terms', (string) $conexion->refresh()->ultimo_error);
        $this->assertSame(0, MetricaDiaria::count());
    }

    public function test_un_external_id_que_no_es_uuid_se_rechaza_claramente(): void
    {
        $cuenta = $this->cuentaSpotify(externalId: '12345');
        $this->conexion($cuenta);
        $this->fakeSpotify([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UUID');

        app(SpotifyConnector::class)->sincronizar($cuenta);
    }

    public function test_el_callback_guarda_los_tokens_y_comprueba_la_cuenta(): void
    {
        $cuenta = $this->cuentaSpotify();
        $this->fakeSpotify([]);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.spotify' => ['nonce' => 'n', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/spotify/callback?state=n&code=ELCODE')
            ->assertRedirect();

        $conexion = ConexionApi::sole();
        $this->assertSame('oauth', $conexion->metodo);
        $this->assertSame('access-nuevo', $conexion->access_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ad_accounts/' . self::CUENTA));
    }

    public function test_el_callback_con_state_falsificado_no_conecta(): void
    {
        $cuenta = $this->cuentaSpotify();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.spotify' => ['nonce' => 'nonceseguro', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/spotify/callback?state=intruso&code=ELCODE');

        $this->assertSame(0, ConexionApi::count());
    }

    public function test_la_ficha_de_una_cuenta_de_spotify_renderiza(): void
    {
        $cuenta = $this->cuentaSpotify();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/cuentas/' . $cuenta->id . '/edit')
            ->assertSuccessful()
            ->assertSee('Conectar con Spotify');
    }

    public function test_el_comando_de_sincronizacion_incluye_spotify(): void
    {
        $cuenta = $this->cuentaSpotify();

        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('no tiene conexión con la API')
            ->assertFailed();
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }
}

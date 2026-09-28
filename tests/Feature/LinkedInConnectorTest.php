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
use App\Services\Sync\LinkedInConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de LinkedIn Ads. Todas las llamadas van fakeadas.
 *
 * Lo propio de LinkedIn, que es lo que se comprueba aquí: adAnalytics devuelve
 * URNs en vez de nombres de campaña (hacen falta dos llamadas y un cruce), la
 * fecha viene troceada en year/month/day, el coste es un decimal en la moneda
 * de la cuenta, y las conversiones se reparten en dos campos según cómo mida
 * el cliente.
 */
class LinkedInConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.linkedin_ads.client_id'     => 'cliente',
            'services.linkedin_ads.client_secret' => 'secreto',
            'services.linkedin_ads.api_version'   => '202505',
        ]);
    }

    private function cuentaLinkedin(?string $tipos = null, string $externalId = '512345'): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'       => $cliente->id,
            'plataforma'       => 'linkedin',
            'nombre_cuenta'    => 'Mi universidad · LinkedIn',
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
            'refresh_token' => 'refresh-anual',
            'expires_at'    => now()->addDays(30),
        ], $extras));
    }

    /** Un elemento de adAnalytics tal como lo devuelve LinkedIn. */
    private function elemento(string $campanaId, string $fecha, array $extras = []): array
    {
        [$a, $m, $d] = array_map('intval', explode('-', $fecha));

        return array_merge([
            'dateRange'           => ['start' => ['year' => $a, 'month' => $m, 'day' => $d]],
            'pivotValues'         => ['urn:li:sponsoredCampaign:' . $campanaId],
            'impressions'         => 15290,
            'clicks'              => 57,
            'costInLocalCurrency' => '145.20',
            'oneClickLeads'       => 4,
            'externalWebsiteConversions' => 9,
        ], $extras);
    }

    /**
     * @param  array<int,array<string,mixed>>  $elementos
     * @param  array<int,array<string,mixed>>  $campanas
     */
    private function fakeLinkedin(array $elementos, array $campanas): void
    {
        Http::fake(function ($request) use ($elementos, $campanas) {
            $url = $request->url();

            if (str_contains($url, 'oauth/v2/accessToken')) {
                return Http::response(['access_token' => 'access-nuevo', 'expires_in' => 5184000]);
            }

            if (str_contains($url, '/rest/adAnalytics')) {
                return Http::response(['elements' => $elementos]);
            }

            return Http::response(['elements' => $campanas]);
        });
    }

    public function test_cruza_los_urns_con_los_nombres_de_campana(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);

        $this->fakeLinkedin(
            elementos: [$this->elemento('777', '2026-04-01')],
            campanas: [['id' => '777', 'name' => 'MASTER - LEADGEN']]
        );

        $res = app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(1, $res['filas']);
        $this->assertSame('master - leadgen', Linea::sole()->match_key);

        $dia = MetricaDiaria::sole();
        $this->assertSame(15290, $dia->impresiones);
        $this->assertSame(57, $dia->clics);
        $this->assertEquals(145.20, $dia->gasto_real, 'El coste ya viene en euros, no en micros');
        $this->assertSame(0, $dia->alcance);
        $this->assertEquals('2026-04-01', $dia->fecha->toDateString(), 'La fecha llega troceada en year/month/day');

        $this->assertStringContainsString('LinkedIn Ads API', ImportLog::find($res['import_id'])->fichero);
    }

    public function test_prioriza_los_formularios_sobre_las_conversiones_de_web(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);

        // El mismo lead se registra en los dos campos: sumarlos daría 13.
        $this->fakeLinkedin(
            elementos: [$this->elemento('777', '2026-04-01')],
            campanas: [['id' => '777', 'name' => 'MASTER - LEADGEN']]
        );

        app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(4, MetricaDiaria::sole()->leads, 'Manda oneClickLeads por prioridad');
    }

    public function test_usa_las_conversiones_de_web_si_no_hay_formularios(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);

        $this->fakeLinkedin(
            elementos: [$this->elemento('777', '2026-04-01', ['oneClickLeads' => 0])],
            campanas: [['id' => '777', 'name' => 'GRADOS - TRAFICO']]
        );

        app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(9, MetricaDiaria::sole()->leads);
    }

    public function test_cada_cuenta_puede_elegir_su_campo_de_conversion(): void
    {
        $cuenta = $this->cuentaLinkedin('externalWebsiteConversions');
        $this->conexion($cuenta);

        $this->fakeLinkedin(
            elementos: [$this->elemento('777', '2026-04-01')],
            campanas: [['id' => '777', 'name' => 'MASTER - LEADGEN']]
        );

        app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertEquals(9, MetricaDiaria::sole()->leads);
    }

    /** Una campaña sin nombre resoluble no puede crear una línea llamada "urn:li:…". */
    public function test_una_campana_sin_nombre_se_descarta_en_vez_de_ensuciar(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);

        $this->fakeLinkedin(
            elementos: [
                $this->elemento('777', '2026-04-01'),
                $this->elemento('999', '2026-04-01'), // no viene en adCampaigns
            ],
            campanas: [['id' => '777', 'name' => 'MASTER - LEADGEN']]
        );

        $res = app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $this->assertSame(1, $res['filas']);
        $this->assertSame(1, $res['filas_error'], 'La fila descartada queda contada en el log');
        $this->assertSame(1, Linea::count());
    }

    public function test_guarda_el_presupuesto_sin_tocar_lo_facturable(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);

        $this->fakeLinkedin(
            elementos: [$this->elemento('777', '2026-04-01')],
            campanas: [[
                'id'          => '777',
                'name'        => 'MASTER - LEADGEN',
                'dailyBudget' => ['currencyCode' => 'EUR', 'amount' => '60.00'],
            ]]
        );

        app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');

        $linea = Linea::sole();
        $this->assertEquals(60.00, $linea->presupuesto_plataforma);
        $this->assertSame('diario', $linea->presupuesto_plataforma_tipo);
        $this->assertSame(0, PresupuestoEvento::count());
    }

    public function test_manda_la_version_de_api_y_el_protocolo(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta);
        $this->fakeLinkedin([], []);

        app(LinkedInConnector::class)->sincronizar($cuenta);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.linkedin.com')) {
                return true;
            }

            return $request->header('LinkedIn-Version')[0] === '202505'
                && $request->header('X-Restli-Protocol-Version')[0] === '2.0.0';
        });
    }

    public function test_renueva_el_token_caducado_con_el_refresh(): void
    {
        $cuenta   = $this->cuentaLinkedin();
        $conexion = $this->conexion($cuenta, ['expires_at' => now()->addDay()]);

        $this->fakeLinkedin([], []);

        app(LinkedInConnector::class)->sincronizar($cuenta);

        $conexion->refresh();
        $this->assertSame('access-nuevo', $conexion->access_token);
        $this->assertTrue($conexion->expires_at->gt(now()->addDays(30)));
    }

    public function test_no_renueva_un_token_que_sigue_vigente(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $this->conexion($cuenta, ['expires_at' => now()->addDays(30)]);

        $renovaciones = 0;
        Http::fake(function ($request) use (&$renovaciones) {
            if (str_contains($request->url(), 'accessToken')) {
                $renovaciones++;
            }

            return Http::response(['elements' => []]);
        });

        app(LinkedInConnector::class)->sincronizar($cuenta);

        $this->assertSame(0, $renovaciones);
    }

    public function test_un_error_queda_registrado_en_la_conexion(): void
    {
        $cuenta   = $this->cuentaLinkedin();
        $conexion = $this->conexion($cuenta);

        Http::fake(fn () => Http::response(['message' => 'Not enough permissions to access: adAnalytics'], 403));

        try {
            app(LinkedInConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-01');
            $this->fail('Debe lanzar para que el comando o el botón avisen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not enough permissions', $e->getMessage());
        }

        $this->assertStringContainsString('permissions', (string) $conexion->refresh()->ultimo_error);
        $this->assertSame(0, MetricaDiaria::count());
    }

    public function test_falta_el_external_id_y_se_rechaza_claramente(): void
    {
        $cuenta = $this->cuentaLinkedin(externalId: '');
        $this->conexion($cuenta);
        $this->fakeLinkedin([], []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('external_id');

        app(LinkedInConnector::class)->sincronizar($cuenta);
    }

    public function test_el_callback_con_state_falsificado_no_conecta(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['conexiones.linkedin' => ['nonce' => 'nonceseguro', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/linkedin/callback?state=intruso&code=ELCODE');

        $this->assertSame(0, ConexionApi::count());
    }

    public function test_la_ficha_de_una_cuenta_de_linkedin_renderiza(): void
    {
        $cuenta = $this->cuentaLinkedin();
        $admin  = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@olimedia.es',
            'password' => 'secretito',
            'es_admin' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/cuentas/' . $cuenta->id . '/edit')
            ->assertSuccessful()
            ->assertSee('Conectar con LinkedIn');
    }

    public function test_el_comando_de_sincronizacion_incluye_linkedin(): void
    {
        $cuenta = $this->cuentaLinkedin();

        // Sin conexión: el motivo debe ser ese, no "plataforma sin conector".
        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('no tiene conexión con la API')
            ->assertFailed();
    }
}

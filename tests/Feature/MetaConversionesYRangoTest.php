<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\MetricaDiaria;
use App\Services\Sync\MetaConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dos cosas que se tocaron juntas:
 *
 *  - Qué cuenta como "lead". Meta devuelve varios tipos de conversión a la vez
 *    y cada cuenta mide distinto (formulario de Lead Ads vs registro en web con
 *    píxel). Se coge el primero de una lista por prioridad, nunca la suma,
 *    porque el agregado y el del píxel son el mismo evento contado dos veces.
 *  - Sincronizar un rango de fechas concreto, para recuperar histórico.
 */
class MetaConversionesYRangoTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaMeta(?string $tipos = null): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        $cuenta = Cuenta::create([
            'cliente_id'       => $cliente->id,
            'plataforma'       => 'meta',
            'nombre_cuenta'    => 'Mi universidad · Meta',
            'external_id'      => '123',
            'tipos_conversion' => $tipos,
            'activo'           => true,
        ]);

        ConexionApi::create([
            'cuenta_id'    => $cuenta->id,
            'metodo'       => 'system_user',
            'access_token' => 'token-largo',
        ]);

        return $cuenta;
    }

    /** @param array<int,array<string,mixed>> $acciones */
    private function fakeInsights(array $acciones, ?string $fecha = null): void
    {
        $fecha ??= now()->toDateString();

        Http::fake(function ($request) use ($acciones, $fecha) {
            if (str_contains($request->url(), '/insights')) {
                return Http::response(['data' => [[
                    'date_start'    => $fecha,
                    'campaign_name' => 'CONV - GRADOS',
                    'impressions'   => '112402',
                    'reach'         => '51489',
                    'clicks'        => '893',
                    'spend'         => '512.83',
                    'actions'       => $acciones,
                ]]]);
            }

            return Http::response(['data' => []]); // campaigns / adsets
        });
    }

    public function test_cuenta_los_registros_de_web_ademas_de_los_leads_de_formulario(): void
    {
        $cuenta = $this->cuentaMeta();

        // Campaña de conversión web: no hay 'lead', hay registros del píxel.
        $this->fakeInsights([
            ['action_type' => 'link_click', 'value' => '800'],
            ['action_type' => 'complete_registration', 'value' => '4'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertEquals(4, MetricaDiaria::sole()->leads);
    }

    public function test_no_duplica_cuando_meta_devuelve_el_agregado_y_el_del_pixel(): void
    {
        $cuenta = $this->cuentaMeta();

        // Mismo evento contado dos veces por Meta: sumarlos daría 8 en vez de 4.
        $this->fakeInsights([
            ['action_type' => 'complete_registration', 'value' => '4'],
            ['action_type' => 'offsite_conversion.fb_pixel_complete_registration', 'value' => '4'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertEquals(4, MetricaDiaria::sole()->leads);
    }

    public function test_el_formulario_de_lead_ads_manda_sobre_el_resto(): void
    {
        $cuenta = $this->cuentaMeta();

        $this->fakeInsights([
            ['action_type' => 'complete_registration', 'value' => '9'],
            ['action_type' => 'lead', 'value' => '3'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertEquals(3, MetricaDiaria::sole()->leads, 'La lista por defecto prioriza "lead"');
    }

    public function test_cada_cuenta_puede_elegir_su_tipo_de_conversion(): void
    {
        // Este cliente mide con un evento propio.
        $cuenta = $this->cuentaMeta('offsite_conversion.fb_pixel_custom, lead');

        $this->fakeInsights([
            ['action_type' => 'lead', 'value' => '3'],
            ['action_type' => 'offsite_conversion.fb_pixel_custom', 'value' => '11'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $this->assertEquals(11, MetricaDiaria::sole()->leads);
    }

    public function test_sin_conversiones_conocidas_quedan_a_cero_sin_romper(): void
    {
        $cuenta = $this->cuentaMeta();

        // Campaña de alcance o de seguidores: no tiene registros.
        $this->fakeInsights([
            ['action_type' => 'post_engagement', 'value' => '1200'],
        ]);

        app(MetaConnector::class)->sincronizar($cuenta);

        $metrica = MetricaDiaria::sole();
        $this->assertEquals(0, $metrica->leads);
        $this->assertSame(893, $metrica->clics, 'El resto de columnas entra igual');
        $this->assertSame(51489, $metrica->alcance);
    }

    public function test_sincroniza_un_rango_de_fechas_concreto(): void
    {
        $cuenta = $this->cuentaMeta();
        $this->fakeInsights([['action_type' => 'lead', 'value' => '2']], '2026-04-15');

        $res = app(MetaConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-30');

        // La ventana pedida viaja a Graph tal cual.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/insights')
            && str_contains(urldecode($request->url()), '"since":"2026-04-01"')
            && str_contains(urldecode($request->url()), '"until":"2026-04-30"'));

        $this->assertSame(1, $res['filas']);
        $this->assertStringContainsString('2026-04-01', ImportLog::find($res['import_id'])->fichero);
    }

    public function test_el_rango_al_reves_se_endereza(): void
    {
        $cuenta = $this->cuentaMeta();
        $this->fakeInsights([], '2026-04-10');

        app(MetaConnector::class)->sincronizarRango($cuenta, '2026-04-30', '2026-04-01');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/insights')
            || str_contains(urldecode($request->url()), '"since":"2026-04-01"'));
    }

    public function test_el_final_del_rango_no_puede_ser_futuro(): void
    {
        $cuenta = $this->cuentaMeta();
        $this->fakeInsights([]);

        app(MetaConnector::class)->sincronizarRango(
            $cuenta,
            now()->subDays(2)->toDateString(),
            now()->addYear()->toDateString()
        );

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/insights')
            || str_contains(urldecode($request->url()), '"until":"' . now()->toDateString() . '"'));
    }

    public function test_un_rango_que_empieza_en_el_futuro_se_rechaza(): void
    {
        $cuenta = $this->cuentaMeta();
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('futuro');

        app(MetaConnector::class)->sincronizarRango(
            $cuenta,
            now()->addWeek()->toDateString(),
            now()->addMonth()->toDateString()
        );
    }

    public function test_reimportar_un_rango_ya_bajado_no_duplica(): void
    {
        $cuenta = $this->cuentaMeta();
        $this->fakeInsights([['action_type' => 'lead', 'value' => '2']], '2026-04-15');

        app(MetaConnector::class)->sincronizarRango($cuenta, '2026-04-01', '2026-04-30');
        app(MetaConnector::class)->sincronizarRango($cuenta, '2026-04-10', '2026-04-20');

        $this->assertSame(1, MetricaDiaria::count());
    }
}

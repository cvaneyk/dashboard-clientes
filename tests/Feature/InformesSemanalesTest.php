<?php

namespace Tests\Feature;

use App\Mail\InformeMail;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PlantillaEmail;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\Informes\InformesSemanales;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Informes semanales por email. Lo que más importa aquí es lo mismo que en el
 * dashboard: el email del cliente lleva coste-cliente y NUNCA gasto_real.
 */
class InformesSemanalesTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes 05/10/2026 → se informa la semana del 28/09 al 04/10. */
    private const LUNES = '2026-10-05';

    private function cliente(string $slug, array $extra = [], bool $conTramo = true, float $gasto = 100): Cliente
    {
        $cliente = Cliente::create($extra + ['nombre' => 'Cliente ' . $slug, 'slug' => $slug, 'activo' => true]);

        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Cuenta ' . $slug,
            'activo'        => true,
        ]);

        $linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => 'CAMPANA ' . $slug,
            'match_key'  => Linea::normalizar('CAMPANA ' . $slug),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        if ($conTramo) {
            PresupuestoEvento::create([
                'linea_id'   => $linea->id,
                'desde'      => '2026-09-01',
                'hasta'      => null,
                'porcentaje' => 20,
                'estado'     => 'activa',
            ]);
        }

        if ($gasto > 0) {
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
                'gasto_real'  => $gasto,
            ]);
        }

        return $cliente;
    }

    private function semana(): array
    {
        return InformesSemanales::semanaAnterior(Carbon::parse(self::LUNES));
    }

    public function test_la_semana_informada_es_la_anterior_de_lunes_a_domingo(): void
    {
        [$desde, $hasta] = $this->semana();

        $this->assertSame('2026-09-28', $desde->toDateString());
        $this->assertSame('2026-10-04', $hasta->toDateString());
    }

    public function test_el_informe_del_cliente_lleva_coste_cliente_y_nunca_el_gasto_real(): void
    {
        $cliente = $this->cliente('uni');

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        // 100 € de gasto + 20 % = 120 €; CPL = 120 / 3 = 40 €.
        $this->assertStringContainsString('120,00 €', $informe['cuerpo']);
        $this->assertStringContainsString('40,00 €', $informe['cuerpo']);
        $this->assertStringNotContainsString('100,00', $informe['cuerpo']);
        $this->assertStringContainsString('Cliente uni', $informe['asunto']);
        $this->assertStringContainsString('28/09 al 04/10/2026', $informe['asunto']);
        $this->assertFalse($informe['sin_actividad']);
    }

    public function test_las_variables_de_texto_se_escapan(): void
    {
        $cliente = $this->cliente('xss', ['nombre' => '<script>alert(1)</script>']);
        PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL)->update(['cuerpo' => '<p>Hola {cliente}</p>']);

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        $this->assertStringNotContainsString('<script>', $informe['cuerpo']);
        $this->assertStringContainsString('&lt;script&gt;', $informe['cuerpo']);
    }

    public function test_el_resumen_interno_lleva_gasto_margen_y_avisa_del_gasto_sin_margen(): void
    {
        $this->cliente('uni');
        $this->cliente('sin-tramo', conTramo: false, gasto: 50);

        $informe = app(InformesSemanales::class)->paraAdmin(...$this->semana());

        $this->assertStringContainsString('150,00 €', $informe['cuerpo']); // gasto real total
        $this->assertStringContainsString('170,00 €', $informe['cuerpo']); // facturado: 100 + 20 % y 50 al coste
        $this->assertStringContainsString('20,00 €', $informe['cuerpo']);  // margen: 170 - 150
        $this->assertStringContainsString('Gasto sin margen aplicado: Cliente sin-tramo', $informe['cuerpo']);
        $this->assertStringNotContainsString('Gasto sin margen aplicado: Cliente uni', $informe['cuerpo']);
    }

    public function test_el_comando_envia_a_los_clientes_activados_y_al_admin(): void
    {
        Mail::fake();

        User::create(['name' => 'AM', 'email' => 'am@example.com', 'password' => 'x', 'es_admin' => true]);

        $this->cliente('uni', ['email_informes' => 'a@uni.es, b@uni.es', 'informe_semanal' => true]);
        $this->cliente('desactivado', ['email_informes' => 'c@otro.es', 'informe_semanal' => false]);
        $this->cliente('parado', ['email_informes' => 'd@parado.es', 'informe_semanal' => true], gasto: 0);

        $this->artisan('portal:informes', ['--fecha' => self::LUNES])->assertSuccessful();

        Mail::assertSent(InformeMail::class, 2);
        Mail::assertSent(InformeMail::class, fn (InformeMail $m) => $m->hasTo('a@uni.es') && $m->hasTo('b@uni.es')
            && ! str_contains($m->cuerpo, 'Gasto real'));
        Mail::assertSent(InformeMail::class, fn (InformeMail $m) => $m->hasTo('am@example.com')
            && str_contains($m->cuerpo, 'Gasto real'));
        Mail::assertNotSent(InformeMail::class, fn (InformeMail $m) => $m->hasTo('c@otro.es') || $m->hasTo('d@parado.es'));

        $this->assertNotNull(Cliente::where('slug', 'uni')->first()->ultimo_informe_en);
    }

    public function test_no_se_envia_dos_veces_el_mismo_dia(): void
    {
        Mail::fake();
        $this->cliente('uni', ['email_informes' => 'a@uni.es', 'informe_semanal' => true]);

        $this->artisan('portal:informes', ['--fecha' => self::LUNES, '--solo' => 'clientes'])->assertSuccessful();
        $this->artisan('portal:informes', ['--fecha' => self::LUNES, '--solo' => 'clientes'])->assertSuccessful();

        Mail::assertSent(InformeMail::class, 1);
    }

    public function test_para_redirige_la_prueba_sin_tocar_a_los_destinatarios_reales(): void
    {
        Mail::fake();
        $this->cliente('uni', ['email_informes' => 'a@uni.es', 'informe_semanal' => true]);

        $this->artisan('portal:informes', [
            '--fecha' => self::LUNES, '--cliente' => 'uni', '--para' => 'yo@example.com',
        ])->assertSuccessful();

        Mail::assertSent(InformeMail::class, 1);
        Mail::assertSent(InformeMail::class, fn (InformeMail $m) => $m->hasTo('yo@example.com') && ! $m->hasTo('a@uni.es'));
        $this->assertNull(Cliente::where('slug', 'uni')->first()->ultimo_informe_en);
    }

    /** Añade una métrica más a la primera línea del cliente. */
    private function metrica(Cliente $cliente, string $fecha, float $gasto, int $clics, float $leads): void
    {
        $linea = $cliente->lineas()->firstOrFail();

        MetricaDiaria::create([
            'cliente_id'  => $cliente->id,
            'cuenta_id'   => $linea->cuenta_id,
            'linea_id'    => $linea->id,
            'plataforma'  => $linea->plataforma,
            'match_key'   => $linea->match_key,
            'fecha'       => $fecha,
            'impresiones' => 1000,
            'clics'       => $clics,
            'leads'       => $leads,
            'gasto_real'  => $gasto,
        ]);
    }

    /** Un segundo canal para el cliente, con gasto y sin ningún lead. */
    private function canalSinLeads(Cliente $cliente, string $plataforma, float $gasto): void
    {
        $cuenta = Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => $plataforma,
            'nombre_cuenta' => 'Cuenta ' . $plataforma,
            'activo'        => true,
        ]);

        $nombre = 'CAMPANA ' . strtoupper($plataforma);

        $linea = Linea::create([
            'cliente_id' => $cliente->id,
            'cuenta_id'  => $cuenta->id,
            'plataforma' => $plataforma,
            'nombre'     => $nombre,
            'match_key'  => Linea::normalizar($nombre),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        PresupuestoEvento::create([
            'linea_id'   => $linea->id,
            'desde'      => '2026-09-01',
            'hasta'      => null,
            'porcentaje' => 20,
            'estado'     => 'activa',
        ]);

        MetricaDiaria::create([
            'cliente_id'  => $cliente->id,
            'cuenta_id'   => $cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => $plataforma,
            'match_key'   => $linea->match_key,
            'fecha'       => '2026-09-30',
            'impresiones' => 5000,
            'clics'       => 100,
            'leads'       => 0,
            'gasto_real'  => $gasto,
        ]);
    }

    /**
     * El invariante que no se puede romper: los comentarios y los gráficos
     * nuevos manejan las mismas cifras, así que hay que volver a comprobar que
     * ni el gasto real ni el margen se cuelan en el email del cliente.
     */
    public function test_los_comentarios_y_graficos_del_cliente_no_filtran_el_gasto_real(): void
    {
        $cliente = $this->cliente('uni');
        $this->canalSinLeads($cliente, 'google', 400);

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        // Gasto real: 100 + 400 = 500. Facturado: 120 + 480 = 600.
        $this->assertStringContainsString('600,00 €', $informe['cuerpo']);
        $this->assertStringNotContainsString('500,00', $informe['cuerpo']);
        $this->assertStringNotContainsString('400,00', $informe['cuerpo']);
        $this->assertStringNotContainsString('Gasto real', $informe['cuerpo']);
        $this->assertStringNotContainsString('argen', $informe['cuerpo']); // Margen / margen
    }

    /**
     * Los gráficos van con tablas y colores de fondo a propósito: en un correo
     * no hay JavaScript, y Gmail y Outlook eliminan el SVG. Si alguien los
     * reescribe con Chart.js o con un SVG, el informe sale en blanco.
     */
    public function test_los_graficos_no_llevan_javascript_ni_svg(): void
    {
        $cliente = $this->cliente('uni');
        $this->canalSinLeads($cliente, 'google', 400);

        $cuerpos = [
            app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana())['cuerpo'],
            app(InformesSemanales::class)->paraAdmin(...$this->semana())['cuerpo'],
        ];

        foreach ($cuerpos as $cuerpo) {
            $this->assertStringNotContainsString('<script', $cuerpo);
            $this->assertStringNotContainsString('<svg', $cuerpo);
            $this->assertStringNotContainsString('<canvas', $cuerpo);
            $this->assertStringNotContainsString('javascript:', $cuerpo);
            // Y sí lleva barras: celdas con color de fondo y ancho en porcentaje.
            $this->assertMatchesRegularExpression('/<td width="\d+%"/', $cuerpo);
        }
    }

    public function test_el_comentario_automatico_avisa_de_que_sube_el_coste_por_lead(): void
    {
        $cliente = $this->cliente('uni');
        // La semana informada deja CPL 40 € (120 € / 3 leads). La anterior,
        // con los mismos 100 € de gasto y 10 leads, lo dejaba en 12 €.
        $this->metrica($cliente, '2026-09-23', gasto: 100, clics: 50, leads: 10);

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        $this->assertStringContainsString('coste por lead ha subido', $informe['cuerpo']);
        $this->assertStringContainsString('12,00 €', $informe['cuerpo']);
        $this->assertStringContainsString('40,00 €', $informe['cuerpo']);
        $this->assertStringContainsString('menos leads con una inversión parecida', $informe['cuerpo']);
    }

    public function test_el_comentario_senala_el_canal_que_gasta_sin_dar_leads(): void
    {
        $cliente = $this->cliente('uni');
        $this->canalSinLeads($cliente, 'google', 400);

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        // Google se lleva 480 de 600 € facturados (80 %) y no trae ni un lead.
        $this->assertStringContainsString('Google Ads se ha llevado el 80 % de la inversión', $informe['cuerpo']);
        $this->assertStringContainsString('no ha generado ningún lead', $informe['cuerpo']);
    }

    public function test_sin_semana_anterior_el_comentario_lo_dice_en_vez_de_inventar_una_comparacion(): void
    {
        $cliente = $this->cliente('uni');

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());

        $this->assertStringContainsString('primera semana con datos registrados', $informe['cuerpo']);
        $this->assertStringNotContainsString('ha subido', $informe['cuerpo']);
    }

    /**
     * La nota manual solo vale para la semana a la que se le puso fecha. Sin
     * esto, una nota escrita una vez saldría en todos los informes siguientes.
     */
    public function test_la_nota_manual_sale_solo_en_la_semana_a_la_que_se_refiere(): void
    {
        $cliente = $this->cliente('uni', [
            'comentario_semanal'        => 'El jueves bajamos pujas en Google.',
            'comentario_semanal_semana' => '2026-09-28',
        ]);

        $informe = app(InformesSemanales::class)->paraCliente($cliente, ...$this->semana());
        $this->assertStringContainsString('El jueves bajamos pujas en Google.', $informe['cuerpo']);

        // La misma nota, fechada en otra semana, no aparece.
        $cliente->update(['comentario_semanal_semana' => '2026-09-21']);

        $informe = app(InformesSemanales::class)->paraCliente($cliente->fresh(), ...$this->semana());
        $this->assertStringNotContainsString('bajamos pujas', $informe['cuerpo']);
    }

    public function test_el_diagnostico_interno_avisa_del_gasto_sin_margen(): void
    {
        $this->cliente('uni');
        $this->cliente('sin-tramo', conTramo: false, gasto: 50);

        $informe = app(InformesSemanales::class)->paraAdmin(...$this->semana());

        $this->assertStringContainsString('de gasto que se factura a coste, sin margen', $informe['cuerpo']);
        $this->assertStringContainsString('Cliente sin-tramo', $informe['cuerpo']);
        $this->assertStringContainsString('Margen por cliente', $informe['cuerpo']);
    }

    public function test_el_panel_muestra_las_plantillas_y_los_campos_del_cliente(): void
    {
        $admin     = User::create(['name' => 'AM', 'email' => 'am@example.com', 'password' => 'x', 'es_admin' => true]);
        $cliente   = $this->cliente('uni');
        $plantilla = PlantillaEmail::deClave(PlantillaEmail::INFORME_SEMANAL);

        $this->actingAs($admin, 'admin');

        $this->get('/admin/plantillas-email')->assertOk()->assertSee('Informe semanal del cliente');
        $this->get("/admin/plantillas-email/{$plantilla->id}/edit")->assertOk()->assertSee('{tabla_canales}');
        $this->get("/admin/clientes/{$cliente->id}/edit")->assertOk()->assertSee('Email para informes');
    }
}

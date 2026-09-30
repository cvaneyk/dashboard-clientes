<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use App\Services\Import\CampaignImporter;
use App\Services\Sync\Conectores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spotify y OpenAI dadas de alta para la vía manual: se pueden crear cuentas,
 * importar informes y pintarlas en el dashboard, pero todavía no tienen ni
 * lector de informes ni conector de API.
 *
 * Lo que se fija aquí es que estar dada de alta y tener conector son cosas
 * distintas, y que la que falta lo diga con claridad en vez de romper.
 */
class PlataformasNuevasTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(string $plataforma): Cuenta
    {
        $cliente = Cliente::firstOrCreate(
            ['slug' => 'mi-universidad'],
            ['nombre' => 'Mi universidad', 'activo' => true]
        );

        return Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => $plataforma,
            'nombre_cuenta' => 'Mi universidad · ' . $plataforma,
            'activo'        => true,
        ]);
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

    public function test_las_dos_plataformas_se_pueden_elegir_al_crear_una_cuenta(): void
    {
        $this->assertArrayHasKey('spotify', Cuenta::PLATAFORMAS);
        $this->assertArrayHasKey('openai', Cuenta::PLATAFORMAS);

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/cuentas/create')
            ->assertSuccessful()
            ->assertSee('Spotify Ads')
            ->assertSee('OpenAI Ads');
    }

    public function test_se_puede_dar_de_alta_una_cuenta_y_sus_lineas(): void
    {
        $cuenta = $this->cuenta('spotify');

        $this->assertSame('spotify', $cuenta->fresh()->plataforma);

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/lineas')
            ->assertSuccessful();
    }

    /** Sin lector todavía: tiene que decirlo, no reventar con un error opaco. */
    public function test_importar_sin_lector_explica_lo_que_falta(): void
    {
        $importer = app(CampaignImporter::class);

        foreach (['spotify', 'openai'] as $plataforma) {
            try {
                $importer->parserFor($plataforma);
                $this->fail("Debería avisar de que falta el lector de {$plataforma}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('lector de informes', $e->getMessage());
                $this->assertStringContainsString($plataforma, $e->getMessage());
            }
        }
    }

    /** Dadas de alta, pero sin conector de API: el comando no debe intentarlo. */
    public function test_no_tienen_conector_de_api_todavia(): void
    {
        $this->assertFalse(Conectores::soportada('spotify'));
        $this->assertFalse(Conectores::soportada('openai'));

        $cuenta = $this->cuenta('openai');

        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('todavía no tiene conector')
            ->assertFailed();
    }

    /** El importador por filas sí funciona: es la vía para cargarlas a mano. */
    public function test_el_dashboard_las_pinta_como_un_canal_mas(): void
    {
        $cuenta = $this->cuenta('spotify');

        app(CampaignImporter::class)->importRows($cuenta, [[
            'match_key'   => 'audio_marca',
            'nombre'      => 'AUDIO_MARCA',
            'fecha'       => now()->toDateString(),
            'impresiones' => 50000,
            'clics'       => 300,
            'leads'       => 12,
            'gasto_real'  => 400,
        ]], ['origen' => 'manual', 'fichero' => 'spotify.csv']);

        PresupuestoEvento::create([
            'linea_id'   => Linea::sole()->id,
            'desde'      => now()->startOfMonth()->toDateString(),
            'porcentaje' => 25,
            'estado'     => 'activa',
        ]);

        $this->assertSame(1, MetricaDiaria::count());

        $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('Spotify Ads')
            ->assertSee('"p":"spotify"', false)
            // 400 € de gasto + 25 % = 500 €, igual que cualquier otro canal.
            ->assertSee('"c":500', false);
    }
}

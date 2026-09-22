<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Services\Import\CampaignImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el punto de entrada por filas (importRows), que es el que usará la
 * sincronización por API. Lo importante aquí es la idempotencia: las APIs
 * reescriben datos hacia atrás, así que una sincronización vuelve a bajar los
 * mismos días una y otra vez y NO puede duplicar ni inflar las cifras.
 */
class CampaignImporterTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(): Cuenta
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
            'activo'        => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function fila(string $matchKey, string $fecha, array $extra = []): array
    {
        return array_merge([
            'match_key'   => $matchKey,
            'nombre'      => strtoupper($matchKey),
            'fecha'       => $fecha,
            'impresiones' => 1000,
            'alcance'     => 800,
            'clics'       => 50,
            'gasto_real'  => 25.5,
            'leads'       => 3,
        ], $extra);
    }

    public function test_crea_la_linea_las_metricas_y_el_log(): void
    {
        $cuenta = $this->cuenta();

        $res = app(CampaignImporter::class)->importRows($cuenta, [
            $this->fila('infantil_leadads', '2026-04-01'),
            $this->fila('infantil_leadads', '2026-04-02'),
            $this->fila('grados_trafico', '2026-04-01'),
        ], ['origen' => 'api', 'fichero' => 'sync google']);

        $this->assertSame(3, $res['filas']);
        $this->assertSame(0, $res['filas_error']);
        $this->assertSame(2, $res['lineas_nuevas']);
        $this->assertSame(76.5, $res['gasto_real']);
        $this->assertSame('2026-04-01', $res['periodo_inicio']);
        $this->assertSame('2026-04-02', $res['periodo_fin']);

        $this->assertSame(2, Linea::where('cuenta_id', $cuenta->id)->count());
        $this->assertSame(3, MetricaDiaria::count());

        $log = ImportLog::find($res['import_id']);
        $this->assertSame('api', $log->origen);
        $this->assertSame('google', $log->plataforma);
        $this->assertSame(3, $log->filas_ok);
        $this->assertSame(2, $log->resumen['lineas_nuevas']);
    }

    public function test_reimportar_los_mismos_dias_actualiza_y_no_duplica(): void
    {
        $cuenta   = $this->cuenta();
        $importer = app(CampaignImporter::class);

        $importer->importRows($cuenta, [
            $this->fila('infantil_leadads', '2026-04-01', ['clics' => 50, 'leads' => 3]),
        ], ['origen' => 'api']);

        // La plataforma reatribuye: mismos día y campaña, cifras corregidas.
        $res = $importer->importRows($cuenta, [
            $this->fila('infantil_leadads', '2026-04-01', ['clics' => 57, 'leads' => 5]),
        ], ['origen' => 'api']);

        $this->assertSame(0, $res['lineas_nuevas'], 'No debe volver a crear la línea');
        $this->assertSame(1, MetricaDiaria::count(), 'No debe duplicar la fila del día');

        $metrica = MetricaDiaria::first();
        $this->assertSame(57, $metrica->clics);
        $this->assertSame('5.00', $metrica->leads);
        $this->assertSame($res['import_id'], $metrica->import_id, 'La métrica pasa a ser del último import');
    }

    public function test_descarta_filas_sin_campana_o_sin_fecha(): void
    {
        $cuenta = $this->cuenta();

        $res = app(CampaignImporter::class)->importRows($cuenta, [
            $this->fila('infantil_leadads', '2026-04-01'),
            $this->fila('', '2026-04-01'),            // sin campaña
            $this->fila('grados_trafico', ''),        // sin fecha
            ['match_key' => 'sin_metricas', 'fecha' => '2026-04-03'], // fila parcial de una API
        ], ['origen' => 'api']);

        $this->assertSame(2, $res['filas']);
        $this->assertSame(2, $res['filas_error']);

        // La fila parcial entra con las métricas a 0, sin reventar.
        $parcial = MetricaDiaria::where('match_key', 'sin_metricas')->first();
        $this->assertNotNull($parcial);
        $this->assertSame(0, $parcial->clics);
        $this->assertSame('0.00', $parcial->gasto_real);

        $this->assertSame(2, ImportLog::find($res['import_id'])->filas_error);
    }

    /**
     * El camino de siempre: archivo descargado a mano. Se comprueba con un CSV de
     * Google real en lo que importa (UTF-16, tabuladores, fechas DD/MM/AAAA y
     * números en formato español), porque es donde han dolido los fallos.
     */
    public function test_importa_un_csv_de_google_subido_a_mano(): void
    {
        $cuenta = $this->cuenta();

        $csv = implode("\n", [
            "Informe de campañas",
            "Campaña\tDía\tImpr.\tCoste\tClics\tConversiones",
            "🟥 INFANTIL_LEADADS\t01/04/2026\t1.529\t45,20\t57\t3",
            "🟥 INFANTIL_LEADADS\t02/04/2026\t2.104\t61,75\t83\t5",
            "Total: cuenta\t\t3.633\t106,95\t140\t8",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'google') . '.csv';
        file_put_contents($path, mb_convert_encoding($csv, 'UTF-16', 'UTF-8'));

        try {
            $res = app(CampaignImporter::class)->import($cuenta, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, $res['filas']);
        $this->assertSame(1, $res['lineas_nuevas']);
        $this->assertSame('2026-04-01', $res['periodo_inicio']);
        $this->assertSame('2026-04-02', $res['periodo_fin']);
        $this->assertEqualsWithDelta(106.95, $res['gasto_real'], 0.001);

        // El emoji de Meta/Google no entra en la clave de emparejado.
        $linea = Linea::where('cuenta_id', $cuenta->id)->sole();
        $this->assertSame('infantil_leadads', $linea->match_key);

        $dia = MetricaDiaria::where('fecha', '2026-04-01')->sole();
        $this->assertEquals(1529, $dia->impresiones);
        $this->assertEquals(57, $dia->clics);
        $this->assertEquals(45.20, $dia->gasto_real);
        $this->assertEquals(3, $dia->leads);

        $this->assertSame('manual', ImportLog::find($res['import_id'])->origen);
    }

    public function test_borrar_el_log_borra_sus_metricas(): void
    {
        $cuenta = $this->cuenta();

        $res = app(CampaignImporter::class)->importRows($cuenta, [
            $this->fila('infantil_leadads', '2026-04-01'),
        ], ['origen' => 'api']);

        ImportLog::find($res['import_id'])->delete();

        $this->assertSame(0, MetricaDiaria::count());
        $this->assertSame(1, Linea::where('cuenta_id', $cuenta->id)->count(), 'La línea se conserva');
    }
}

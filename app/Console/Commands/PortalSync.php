<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Services\Sync\MetaConnector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincroniza por API las cuentas conectadas (hoy: Meta) bajando los últimos
 * días y dejándolos en el importador habitual. La ejecución diaria la programa
 * el scheduler (ver routes/console.php); a mano sirve para probar o re-sincronizar.
 *
 * Ejemplos:
 *   php artisan portal:sync                    → todas las cuentas Meta conectadas
 *   php artisan portal:sync --cuenta=3         → solo esa cuenta
 *   php artisan portal:sync --dias=7           → ventana más ancha (correcciones)
 */
class PortalSync extends Command
{
    protected $signature = 'portal:sync {--cuenta= : ID de una cuenta concreta} {--dias=3 : Días hacia atrás a sincronizar}';

    protected $description = 'Sincroniza por API (Meta) las cuentas conectadas y las importa';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));

        $cuentas = Cuenta::query()
            ->where('activo', true)
            ->where('plataforma', 'meta')
            ->whereHas('conexionApi')
            ->when($this->option('cuenta'), fn ($q, $id) => $q->whereKey($id))
            ->with('cliente')
            ->get();

        if ($cuentas->isEmpty()) {
            $this->info('No hay cuentas de Meta conectadas a la API (se conectan desde su ficha en /admin).');
            return self::SUCCESS;
        }

        $fallidas = 0;

        foreach ($cuentas as $cuenta) {
            $this->line("→ {$cuenta->cliente?->nombre} · {$cuenta->nombre_cuenta}…");

            try {
                $res = app(MetaConnector::class)->sincronizar($cuenta, $dias);

                $this->info(sprintf(
                    '   %d filas · %d líneas nuevas · %.2f € · %s → %s%s',
                    $res['filas'],
                    $res['lineas_nuevas'],
                    $res['gasto_real'],
                    $res['periodo_inicio'] ?? '?',
                    $res['periodo_fin'] ?? '?',
                    $res['filas_error'] > 0 ? sprintf(' · %d filas descartadas', $res['filas_error']) : ''
                ));
            } catch (Throwable $e) {
                $fallidas++;
                // El error ya quedó en conexiones_api.ultimo_error para verlo en /admin.
                $this->error('   ' . $e->getMessage());
            }
        }

        return $fallidas === 0 ? self::SUCCESS : self::FAILURE;
    }
}

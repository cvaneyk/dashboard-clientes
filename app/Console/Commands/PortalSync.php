<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MuestraCuentasSincronizables;
use App\Models\Cuenta;
use App\Services\Sync\Conectores;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincroniza por API las cuentas conectadas (Meta y Google Ads) bajando los últimos
 * días y dejándolos en el importador habitual. La ejecución diaria la programa
 * el scheduler (ver routes/console.php); a mano sirve para probar o re-sincronizar.
 *
 * Ejemplos:
 *   php artisan portal:sync                    → todas las cuentas conectadas
 *   php artisan portal:sync --cuenta=3         → solo esa cuenta
 *   php artisan portal:sync --dias=7           → ventana más ancha (correcciones)
 *   php artisan portal:sync --cuenta=3 --desde=2026-04-01 --hasta=2026-07-31
 *                                              → recuperar histórico de un periodo
 */
class PortalSync extends Command
{
    use MuestraCuentasSincronizables;

    protected $signature = 'portal:sync
        {--cuenta= : ID de una cuenta concreta}
        {--dias=3 : Días hacia atrás a sincronizar}
        {--desde= : Fecha inicial AAAA-MM-DD (ignora --dias)}
        {--hasta= : Fecha final AAAA-MM-DD (por defecto, hoy)}';

    protected $description = 'Sincroniza por API (Meta, Google Ads) las cuentas conectadas y las importa';

    public function handle(): int
    {
        $dias  = max(1, (int) $this->option('dias'));
        $desde = $this->option('desde');
        $hasta = $this->option('hasta');

        $cuentas = Cuenta::query()
            ->where('activo', true)
            ->whereIn('plataforma', Conectores::PLATAFORMAS)
            ->whereHas('conexionApi')
            ->when($this->option('cuenta'), fn ($q, $id) => $q->whereKey($id))
            ->with('cliente')
            ->get();

        if ($cuentas->isEmpty()) {
            // Si han pedido una cuenta concreta, decir por qué se queda fuera;
            // si no, es que sencillamente no hay ninguna conectada todavía.
            if ($id = $this->option('cuenta')) {
                $this->error(sprintf(
                    'No se puede sincronizar la cuenta %s: %s.',
                    $id,
                    $this->motivoNoSincronizable(Cuenta::with('conexionApi')->find($id))
                ));

                $this->mostrarCuentasSincronizables();

                return self::FAILURE;
            }

            $this->info('No hay cuentas conectadas a la API (se conectan desde su ficha en /admin).');
            $this->mostrarCuentasSincronizables();

            return self::SUCCESS;
        }

        $fallidas = 0;

        foreach ($cuentas as $cuenta) {
            $this->line("→ {$cuenta->cliente?->nombre} · {$cuenta->nombre_cuenta}…");

            try {
                $conector = Conectores::para($cuenta);

                $res = ($desde || $hasta)
                    ? $conector->sincronizarRango($cuenta, $desde ?? $hasta, $hasta ?? now()->toDateString())
                    : $conector->sincronizar($cuenta, $dias);

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

<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Services\Import\CampaignImporter;
use Illuminate\Console\Command;

/**
 * Importa un informe (CSV/Excel) a una cuenta publicitaria.
 *
 * Ejemplo:
 *   php artisan portal:import 3 "storage/app/informes/meta-julio.xlsx"
 * donde 3 es el ID de la cuenta (que define cliente y plataforma).
 */
class PortalImport extends Command
{
    protected $signature = 'portal:import {cuenta : ID de la cuenta publicitaria} {path : Ruta al archivo CSV/Excel}';

    protected $description = 'Importa un informe de Meta/Google/TikTok a la base de datos del portal';

    public function handle(CampaignImporter $importer): int
    {
        $cuenta = Cuenta::find($this->argument('cuenta'));
        if (! $cuenta) {
            $this->error('No existe la cuenta con ID ' . $this->argument('cuenta'));
            return self::FAILURE;
        }

        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error('No se encuentra el archivo: ' . $path);
            return self::FAILURE;
        }

        $this->info("Importando «{$path}» → cuenta «{$cuenta->nombre_cuenta}» ({$cuenta->plataforma})…");

        $res = $importer->import($cuenta, $path);

        $this->info(sprintf(
            'Hecho: %d filas, %d líneas nuevas, gasto real %.2f €, periodo %s → %s.',
            $res['filas'],
            $res['lineas_nuevas'],
            $res['gasto_real'],
            $res['periodo_inicio'] ?? '?',
            $res['periodo_fin'] ?? '?'
        ));

        if ($res['filas_error'] > 0) {
            $this->warn(sprintf('%d filas descartadas por no traer campaña o fecha.', $res['filas_error']));
        }

        return self::SUCCESS;
    }
}

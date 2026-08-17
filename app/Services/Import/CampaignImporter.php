<?php

namespace App\Services\Import;

use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use RuntimeException;

/**
 * Coordina la importación de un informe a la base de datos:
 *  - elige el lector según la plataforma de la cuenta,
 *  - empareja cada campaña con su Línea (o la crea si no existe, sin presupuesto),
 *  - vuelca/actualiza las métricas diarias (idempotente: no duplica al reimportar),
 *  - registra la importación en import_logs.
 */
class CampaignImporter
{
    public function parserFor(string $plataforma): Parser
    {
        return match ($plataforma) {
            'meta'   => new MetaParser(),
            'google' => new GoogleParser(),
            'tiktok' => new TikTokParser(),
            default  => throw new RuntimeException("No hay importador para la plataforma: {$plataforma}"),
        };
    }

    /**
     * @return array{filas:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function import(Cuenta $cuenta, string $path): array
    {
        $rows = $this->parserFor($cuenta->plataforma)->parse($path);

        $log = ImportLog::create([
            'cliente_id' => $cuenta->cliente_id,
            'plataforma' => $cuenta->plataforma,
            'fichero'    => basename($path),
        ]);

        $lineasNuevas = 0;
        $filas        = 0;
        $gasto        = 0.0;
        $fechas       = [];

        foreach ($rows as $row) {
            $linea = Linea::firstOrCreate(
                ['cuenta_id' => $cuenta->id, 'match_key' => $row['match_key']],
                [
                    'cliente_id' => $cuenta->cliente_id,
                    'plataforma' => $cuenta->plataforma,
                    'nombre'     => $row['nombre'],
                    'nivel'      => 'campania',
                    'activo'     => true,
                ]
            );
            if ($linea->wasRecentlyCreated) {
                $lineasNuevas++;
            }

            MetricaDiaria::updateOrCreate(
                [
                    'cuenta_id' => $cuenta->id,
                    'match_key' => $row['match_key'],
                    'fecha'     => $row['fecha'],
                ],
                [
                    'cliente_id'  => $cuenta->cliente_id,
                    'linea_id'    => $linea->id,
                    'plataforma'  => $cuenta->plataforma,
                    'impresiones' => $row['impresiones'],
                    'alcance'     => $row['alcance'],
                    'clics'       => $row['clics'],
                    'gasto_real'  => $row['gasto_real'],
                    'leads'       => $row['leads'],
                    'import_id'   => $log->id,
                ]
            );

            $filas++;
            $gasto   += $row['gasto_real'];
            $fechas[] = $row['fecha'];
        }

        $inicio = $fechas ? min($fechas) : null;
        $fin    = $fechas ? max($fechas) : null;

        $log->update([
            'filas_ok'       => $filas,
            'periodo_inicio' => $inicio,
            'periodo_fin'    => $fin,
            'resumen'        => [
                'lineas_nuevas' => $lineasNuevas,
                'gasto_real'    => round($gasto, 2),
            ],
        ]);

        return [
            'filas'          => $filas,
            'lineas_nuevas'  => $lineasNuevas,
            'gasto_real'     => round($gasto, 2),
            'periodo_inicio' => $inicio,
            'periodo_fin'    => $fin,
            'import_id'      => $log->id,
        ];
    }
}

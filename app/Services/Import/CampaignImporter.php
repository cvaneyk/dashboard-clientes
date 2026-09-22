<?php

namespace App\Services\Import;

use App\Models\Cuenta;
use App\Models\ImportLog;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Coordina la importación de datos de campañas a la base de datos.
 *
 * Hay dos puntos de entrada, y ambos acaban en el mismo sitio:
 *   - import()      → informe descargado a mano (CSV/Excel); lo lee el parser de la plataforma.
 *   - importRows()  → filas YA normalizadas; lo usará la sincronización por API.
 *
 * Una fila normalizada tiene la forma que produce Parser::make():
 *   ['match_key','nombre','fecha','impresiones','alcance','clics','gasto_real','leads']
 * De esas claves, 'match_key' y 'fecha' son obligatorias (identifican la fila);
 * el resto se dan por 0 si faltan, para que un conector de API no tenga que
 * rellenar métricas que esa plataforma no devuelve.
 *
 * En los dos casos se:
 *   - empareja cada campaña con su Línea (o se crea, sin presupuesto),
 *   - vuelcan/actualizan las métricas diarias (idempotente: no duplica al reimportar),
 *   - registra la operación en import_logs.
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
     * Importa un informe descargado a mano de la plataforma.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function import(Cuenta $cuenta, string $path): array
    {
        $rows = $this->parserFor($cuenta->plataforma)->parse($path);

        return $this->importRows($cuenta, $rows, [
            'origen'  => 'manual',
            'fichero' => basename($path),
        ]);
    }

    /**
     * Vuelca filas ya normalizadas sobre la cuenta indicada.
     *
     * $meta admite:
     *   'origen'  => 'manual' | 'api'   (por defecto 'manual')
     *   'fichero' => nombre del archivo, o etiqueta de la sincronización
     *   'user_id' => quién lo lanzó; null en procesos automáticos
     *
     * @param  iterable<array<string,mixed>>  $rows
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function importRows(Cuenta $cuenta, iterable $rows, array $meta = []): array
    {
        $log = ImportLog::create([
            'cliente_id' => $cuenta->cliente_id,
            'plataforma' => $cuenta->plataforma,
            'origen'     => $meta['origen'] ?? 'manual',
            'fichero'    => $meta['fichero'] ?? null,
            'user_id'    => array_key_exists('user_id', $meta) ? $meta['user_id'] : Auth::id(),
        ]);

        $lineasNuevas = 0;
        $filas        = 0;
        $errores      = 0;
        $gasto        = 0.0;
        $fechas       = [];

        foreach ($rows as $row) {
            $matchKey = trim((string) ($row['match_key'] ?? ''));
            $fecha    = $this->fechaCanonica($row['fecha'] ?? null);

            // Sin campaña o sin fecha no se puede casar la fila con nada: se descarta
            // y queda contada en filas_error del log.
            if ($matchKey === '' || ! $fecha) {
                $errores++;
                continue;
            }

            $linea = Linea::firstOrCreate(
                ['cuenta_id' => $cuenta->id, 'match_key' => $matchKey],
                [
                    'cliente_id' => $cuenta->cliente_id,
                    'plataforma' => $cuenta->plataforma,
                    'nombre'     => $row['nombre'] ?? $matchKey,
                    'nivel'      => 'campania',
                    'activo'     => true,
                ]
            );
            if ($linea->wasRecentlyCreated) {
                $lineasNuevas++;
            }

            $gastoFila = round((float) ($row['gasto_real'] ?? 0), 2);

            MetricaDiaria::updateOrCreate(
                [
                    'cuenta_id' => $cuenta->id,
                    'match_key' => $matchKey,
                    'fecha'     => $fecha,
                ],
                [
                    'cliente_id'  => $cuenta->cliente_id,
                    'linea_id'    => $linea->id,
                    'plataforma'  => $cuenta->plataforma,
                    'impresiones' => (int) ($row['impresiones'] ?? 0),
                    'alcance'     => (int) ($row['alcance'] ?? 0),
                    'clics'       => (int) ($row['clics'] ?? 0),
                    'gasto_real'  => $gastoFila,
                    'leads'       => round((float) ($row['leads'] ?? 0), 2),
                    'import_id'   => $log->id,
                ]
            );

            $filas++;
            $gasto   += $gastoFila;
            $fechas[] = $fecha;
        }

        $inicio = $fechas ? min($fechas) : null;
        $fin    = $fechas ? max($fechas) : null;

        $log->update([
            'filas_ok'       => $filas,
            'filas_error'    => $errores,
            'periodo_inicio' => $inicio,
            'periodo_fin'    => $fin,
            'resumen'        => [
                'lineas_nuevas' => $lineasNuevas,
                'gasto_real'    => round($gasto, 2),
            ],
        ]);

        return [
            'filas'          => $filas,
            'filas_error'    => $errores,
            'lineas_nuevas'  => $lineasNuevas,
            'gasto_real'     => round($gasto, 2),
            'periodo_inicio' => $inicio,
            'periodo_fin'    => $fin,
            'import_id'      => $log->id,
        ];
    }

    /**
     * Deja la fecha en Y-m-d, que es la forma con la que se guarda y con la que
     * casa el índice único de metricas_diarias.
     *
     * Se aceptan objetos fecha y cadenas que YA empiecen por Y-m-d (lo que
     * devuelven los parsers y lo que suelen devolver las APIs, con o sin hora).
     * Cualquier otro formato se rechaza a propósito: un '01/04/2026' es el 1 de
     * abril en España y el 4 de enero en formato americano, y aquí no se adivina.
     * La conversión de fechas españolas es trabajo del Parser.
     */
    private function fechaCanonica(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        $texto = trim((string) $valor);

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $texto, $m) ? $m[1] : null;
    }
}

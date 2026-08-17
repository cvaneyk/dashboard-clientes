<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * TikTok Ads · Excel (una hoja), con desglose por día.
 * Columnas: Campaign name, By Day, Reach, Impressions, Spend,
 * Clicks (destination), Registrations (website) (leads)…
 * Se salta la fila "Total of N results".
 */
class TikTokParser extends Parser
{
    public function parse(string $path): array
    {
        $ss    = IOFactory::load($path);
        $sheet = $ss->getActiveSheet();
        $rows  = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            return [];
        }

        $col = array_flip(array_map(fn ($c) => trim((string) $c), $rows[0]));
        $get = fn ($r, $name) => isset($col[$name]) ? ($r[$col[$name]] ?? null) : null;

        // Columna de registros (leads) — el nombre trae sufijos variables.
        $regIdx = null;
        foreach ($col as $name => $idx) {
            if (str_starts_with($name, 'Registrations')) {
                $regIdx = $idx;
                break;
            }
        }

        $out = [];
        foreach (array_slice($rows, 1) as $r) {
            $camp = (string) $get($r, 'Campaign name');
            if (trim($camp) === '' || stripos($camp, 'Total of') === 0) {
                continue; // fila de total
            }
            $fecha = $this->fecha($get($r, 'By Day'));
            if (! $fecha) {
                continue;
            }
            $out[] = $this->make(
                $camp,
                $fecha,
                $get($r, 'Impressions'),
                $get($r, 'Reach'),
                $get($r, 'Clicks (destination)'),
                $get($r, 'Spend'),
                $regIdx !== null ? ($r[$regIdx] ?? 0) : 0,
                es: false
            );
        }

        return $out;
    }
}

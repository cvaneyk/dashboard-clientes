<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Meta Ads · Excel (hoja "Raw Data Report"), con desglose por día.
 * Columnas: Nombre de la campaña, Día, Alcance, Impresiones, Importe gastado (EUR),
 * Clics (todos), Registros completados (leads)…
 * Se saltan las filas de totales ("All" y la fila sin campaña).
 */
class MetaParser extends Parser
{
    public function parse(string $path): array
    {
        $ss    = IOFactory::load($path);
        $sheet = $ss->getSheetByName('Raw Data Report') ?: $ss->getActiveSheet();
        $rows  = $sheet->toArray(null, true, false, false);

        // Localiza la fila de cabecera.
        $hi = null;
        foreach ($rows as $i => $r) {
            $vals = array_map(fn ($c) => trim((string) $c), $r);
            if (in_array('Nombre de la campaña', $vals, true)) {
                $hi = $i;
                break;
            }
        }
        if ($hi === null) {
            return [];
        }

        $col = array_flip(array_map(fn ($c) => trim((string) $c), $rows[$hi]));
        $get = fn ($r, $name) => isset($col[$name]) ? ($r[$col[$name]] ?? null) : null;

        $out = [];
        foreach (array_slice($rows, $hi + 1) as $r) {
            $camp  = (string) $get($r, 'Nombre de la campaña');
            $fecha = $this->fecha($get($r, 'Día'));
            if (trim($camp) === '' || ! $fecha) {
                continue; // fila "All" o total general
            }
            $out[] = $this->make(
                $camp,
                $fecha,
                $get($r, 'Impresiones'),
                $get($r, 'Alcance'),
                $get($r, 'Clics (todos)'),
                $get($r, 'Importe gastado (EUR)'),
                $get($r, 'Registros completados'),
                es: false
            );
        }

        return $out;
    }
}

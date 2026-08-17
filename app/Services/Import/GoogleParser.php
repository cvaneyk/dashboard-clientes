<?php

namespace App\Services\Import;

/**
 * Google Ads · CSV (UTF-16, separado por tabuladores), con desglose por día.
 * Columnas: Campaña, Día, Impr., Código de moneda, Coste, CTR, CPC medio, Clics.
 * Números en formato español (1.529 miles / 4,69 decimales).
 * Si el informe incluye "Conversiones", se usa como leads.
 */
class GoogleParser extends Parser
{
    public function parse(string $path): array
    {
        $raw = (string) file_get_contents($path);

        // Detecta y convierte UTF-16 a UTF-8.
        $bom = substr($raw, 0, 2);
        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        } elseif (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16, ISO-8859-1');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // quita BOM UTF-8 si quedara

        $lines = preg_split('/\r\n|\r|\n/', $raw);

        // Localiza la cabecera (línea con "Campaña" y "Clics").
        $hi = null;
        foreach ($lines as $i => $l) {
            if (str_contains($l, 'Campaña') && str_contains($l, 'Clics')) {
                $hi = $i;
                break;
            }
        }
        if ($hi === null) {
            return [];
        }

        $col = array_flip(array_map('trim', str_getcsv($lines[$hi], "\t")));
        $get = fn ($r, $name) => isset($col[$name]) ? ($r[$col[$name]] ?? null) : null;

        // Columna de conversiones (leads), si existe.
        $convIdx = null;
        foreach ($col as $name => $idx) {
            if (stripos($name, 'Conversion') !== false || stripos($name, 'Conversión') !== false) {
                $convIdx = $idx;
                break;
            }
        }

        $out = [];
        foreach (array_slice($lines, $hi + 1) as $l) {
            if (trim($l) === '') {
                continue;
            }
            $r     = str_getcsv($l, "\t");
            $camp  = (string) $get($r, 'Campaña');
            $fecha = $this->fecha($get($r, 'Día'));
            if (trim($camp) === '' || ! $fecha) {
                continue;
            }
            $leads = $convIdx !== null ? ($r[$convIdx] ?? 0) : 0;
            $out[] = $this->make(
                $camp,
                $fecha,
                $get($r, 'Impr.'),
                0, // Google no trae alcance en este informe
                $get($r, 'Clics'),
                $get($r, 'Coste'),
                $leads,
                es: true
            );
        }

        return $out;
    }
}

<?php

namespace App\Services\Import;

use App\Models\Linea;

/**
 * Base de los lectores de informes publicitarios.
 * Cada plataforma (Meta, Google, TikTok…) implementa parse() y devuelve filas
 * NORMALIZADAS con esta forma:
 *   ['match_key','nombre','fecha','impresiones','alcance','clics','gasto_real','leads']
 */
abstract class Parser
{
    /** @return array<int,array<string,mixed>> */
    abstract public function parse(string $path): array;

    /** Número en formato inglés (1234.56 · comas = miles). */
    protected function dec($v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace([',', ' ', '%', '€', '"'], '', trim((string) $v));
        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** Número en formato español (1.234,56 · puntos = miles, coma = decimal). */
    protected function esDec($v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(['"', '%', '€', ' '], '', trim((string) $v));
        $s = str_replace('.', '', $s);   // quita separador de miles
        $s = str_replace(',', '.', $s);  // coma decimal -> punto
        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** Extrae una fecha Y-m-d de un valor de texto; null si no es una fecha. */
    protected function fecha($v): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', trim((string) $v), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Construye una fila normalizada. $es = números en formato español.
     * @return array<string,mixed>
     */
    protected function make(string $nombre, string $fecha, $imp, $alc, $cl, $ga, $le, bool $es = false): array
    {
        $n = fn ($v) => $es ? $this->esDec($v) : $this->dec($v);

        return [
            'match_key'   => Linea::normalizar($nombre),
            'nombre'      => trim($nombre),
            'fecha'       => $fecha,
            'impresiones' => (int) round($n($imp)),
            'alcance'     => (int) round($n($alc)),
            'clics'       => (int) round($n($cl)),
            'gasto_real'  => round($n($ga), 2),
            'leads'       => round($n($le), 2),
        ];
    }
}

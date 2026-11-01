<?php

namespace App\Services\Informes;

/**
 * Gráficos para los emails de informe.
 *
 * POR QUÉ ASÍ: en un correo no hay JavaScript, así que Chart.js (lo que usa el
 * dashboard) no sirve, y el SVG en línea lo eliminan Gmail y Outlook. La única
 * técnica que aguanta en todos es dibujar las barras con tablas anidadas y
 * celdas de color, con los estilos en línea. Outlook renderiza con el motor de
 * Word: nada de flex, ni de hojas de estilo, ni de alturas en divs.
 *
 * Las celdas de la barra llevan `font-size:0;line-height:0` porque el &nbsp;
 * que las rellena, si no, estira la fila a la altura de una línea de texto.
 */
class Graficos
{
    /**
     * Color de cada canal. Es un espejo del objeto CH del dashboard
     * (resources/views/portal/dashboard.blade.php): si cambias uno allí,
     * cámbialo aquí, porque el email no puede leer ese JavaScript.
     */
    private const COLORES = [
        'meta'     => '#1877F2',
        'google'   => '#EA4335',
        'tiktok'   => '#111827',
        'linkedin' => '#0A66C2',
        'spotify'  => '#1DB954',
        'openai'   => '#10A37F',
    ];

    private const GRIS   = '#9ca3af';
    private const ROJO   = '#b91c1c';
    private const ALTURA = 14;

    public static function color(?string $plataforma): string
    {
        return self::COLORES[$plataforma] ?? '#638E76';
    }

    /**
     * Barras horizontales. Cada fila necesita `etiqueta`, `valor` (el número con
     * el que se calcula la longitud) y `texto` (lo que se imprime a la derecha).
     * Opcionales: `color` y `alerta` (true pinta la barra en rojo y marca la
     * fila, para el canal que se lleva dinero sin devolver nada).
     *
     * @param  list<array{etiqueta:string,valor:float,texto:string,color?:string,alerta?:bool}>  $filas
     */
    public static function barras(array $filas, ?string $colorPorDefecto = null): string
    {
        if (! $filas) {
            return '<p style="font-size:14px;color:#6b7280;">Sin datos que representar.</p>';
        }

        // La barra más larga marca la escala. Con todo a cero no hay escala
        // posible: se imprimen las etiquetas y los valores, sin barra.
        $max = max(array_map(fn ($f) => (float) $f['valor'], $filas));

        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:8px 0 18px;">';

        foreach ($filas as $fila) {
            $valor  = (float) $fila['valor'];
            $pct    = $max > 0 ? max(0, min(100, (int) round($valor / $max * 100))) : 0;
            $alerta = ! empty($fila['alerta']);
            $color  = $alerta ? self::ROJO : ($fila['color'] ?? $colorPorDefecto ?? self::GRIS);

            $html .= '<tr>'
                . '<td style="padding:5px 10px 5px 0;font-size:13px;color:#374151;white-space:nowrap;">'
                . e($fila['etiqueta']) . '</td>'
                . '<td style="padding:5px 0;width:62%;">' . self::barra($pct, $color) . '</td>'
                . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;white-space:nowrap;'
                . ($alerta ? 'color:' . self::ROJO . ';font-weight:700;' : 'color:#111827;') . '">'
                . e($fila['texto']) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /** Una barra: tabla de dos celdas con los anchos en porcentaje. */
    private static function barra(int $pct, string $color): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;"><tr>';

        // Con 0 % no se pinta nada: una celda de ancho 0 deja un resto visible
        // en algunos clientes de correo.
        if ($pct > 0) {
            $html .= '<td width="' . $pct . '%" style="width:' . $pct . '%;background:' . $color
                . ';height:' . self::ALTURA . 'px;font-size:0;line-height:0;border-radius:3px;">&nbsp;</td>';
        }

        if ($pct < 100) {
            $html .= '<td width="' . (100 - $pct) . '%" style="font-size:0;line-height:0;">&nbsp;</td>';
        }

        return $html . '</tr></table>';
    }

    /**
     * Embudo impresiones → clics → leads, con el porcentaje que pasa de cada
     * paso al siguiente y, si se puede comparar, el de la semana anterior.
     *
     * @param  list<array{nombre:string,valor:string,paso?:string}>  $pasos
     */
    public static function embudo(array $pasos): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 18px;">';

        foreach ($pasos as $i => $paso) {
            $html .= '<tr>'
                . '<td style="padding:4px 14px 4px 0;font-size:13px;color:#6b7280;white-space:nowrap;">'
                . e($paso['nombre']) . '</td>'
                . '<td style="padding:4px 0;font-size:19px;font-weight:700;color:#111827;white-space:nowrap;">'
                . e($paso['valor']) . '</td>'
                . '</tr>';

            if (isset($paso['paso']) && $i < count($pasos) - 1) {
                $html .= '<tr>'
                    . '<td style="padding:0 14px 0 0;font-size:16px;color:#9ca3af;text-align:right;">&darr;</td>'
                    . '<td style="padding:0;font-size:12px;color:#6b7280;">' . e($paso['paso']) . '</td>'
                    . '</tr>';
            }
        }

        return $html . '</table>';
    }
}

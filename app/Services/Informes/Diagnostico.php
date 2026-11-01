<?php

namespace App\Services\Informes;

use Illuminate\Support\Collection;

/**
 * Comentarios automáticos de los informes semanales.
 *
 * QUÉ PUEDE Y QUÉ NO PUEDE DECIR. Esto compara las cifras de la semana con las
 * de la anterior y nombra dónde está el número que se ha movido. No sabe *por
 * qué* se ha movido: que se cambiara la segmentación el jueves, que el cliente
 * cerrara por vacaciones o que haya un formulario roto no está en
 * `metricas_diarias`. Por eso las frases describen el cambio y, como mucho,
 * señalan dónde mirar; nunca afirman una causa ni prometen un resultado. El
 * contexto lo pone el account manager en `clientes.comentario_semanal`.
 *
 * El informe del cliente sale de `kpis()`, que trae el coste facturado y nunca
 * `gasto_real`: aquí no hay forma de colar el margen en un email de cliente.
 */
class Diagnostico
{
    public const PROBLEMA = 'problema';
    public const AVISO    = 'aviso';
    public const BIEN     = 'bien';
    public const NOTA     = 'nota';

    /** Umbrales de variación (en %) a partir de los cuales se comenta. */
    private const GRAVE = 25.0;
    private const LEVE  = 10.0;

    /** Parte de la inversión que convierte en problema que un canal no dé leads. */
    private const PESO_CANAL = 20.0;

    private const COLORES = [
        self::PROBLEMA => '#b91c1c',
        self::AVISO    => '#b45309',
        self::BIEN     => '#15803d',
        self::NOTA     => '#6b7280',
    ];

    // ------------------------------------------------------------- cliente

    /**
     * Comentario del bloque de métricas.
     *
     * @return list<array{nivel:string,texto:string}>
     */
    public static function kpis(array $a, array $b): array
    {
        $out = [];

        if (! self::comparable($b)) {
            return [[
                'nivel' => self::NOTA,
                'texto' => 'Es la primera semana con datos registrados, así que todavía no hay una semana '
                    . 'anterior con la que comparar. A partir del próximo informe se verá la evolución.',
            ]];
        }

        // Lo más grave primero: dinero invertido que no ha devuelto nada.
        if ($a['leads'] == 0 && $a['coste'] > 0) {
            $out[] = [
                'nivel' => self::PROBLEMA,
                'texto' => 'No ha entrado ningún lead esta semana, con ' . self::euros($a['coste'])
                    . ' de inversión. Antes de tocar las campañas conviene comprobar que el formulario '
                    . 'y la página de destino funcionan y que las conversiones se siguen midiendo.',
            ];
        } elseif ($b['leads'] == 0 && $a['leads'] > 0) {
            $out[] = [
                'nivel' => self::BIEN,
                'texto' => 'Han entrado ' . self::numero($a['leads']) . ' leads, frente a ninguno la semana anterior.',
            ];
        } else {
            $varCpl = self::variacion($a['cpl'], $b['cpl']);

            if ($varCpl !== null && $varCpl >= self::LEVE) {
                $out[] = [
                    'nivel' => $varCpl >= self::GRAVE ? self::PROBLEMA : self::AVISO,
                    'texto' => 'El coste por lead ha subido de ' . self::euros($b['cpl']) . ' a '
                        . self::euros($a['cpl']) . ' (' . self::pct($varCpl) . '). '
                        . self::porQueSubeElCpl($a, $b),
                ];
            } elseif ($varCpl !== null && $varCpl <= -self::LEVE) {
                $out[] = [
                    'nivel' => self::BIEN,
                    'texto' => 'El coste por lead ha bajado de ' . self::euros($b['cpl']) . ' a '
                        . self::euros($a['cpl']) . ' (' . self::pct($varCpl) . '): cada lead sale más barato '
                        . 'que la semana pasada.',
                ];
            }
        }

        // El CTR explica si el problema está en el anuncio o más adelante.
        $varCtr = self::variacion($a['ctr'], $b['ctr']);
        if ($varCtr !== null && $varCtr <= -self::GRAVE && $a['impresiones'] > 0) {
            $out[] = [
                'nivel' => self::AVISO,
                'texto' => 'El CTR ha bajado de ' . self::numero($b['ctr']) . ' % a ' . self::numero($a['ctr'])
                    . ' %: el anuncio se sigue mostrando, pero se hace menos clic. Suele apuntar al '
                    . 'creativo o al texto, que se han visto ya demasiadas veces.',
            ];
        }

        // Una bajada de inversión explica por sí sola una bajada de leads, y
        // conviene decirlo para que no se lea como un problema de rendimiento.
        $varCoste = self::variacion($a['coste'], $b['coste']);
        $varLeads = self::variacion($a['leads'], $b['leads']);
        if ($varCoste !== null && $varCoste <= -self::GRAVE && $varLeads !== null && $varLeads < 0) {
            $out[] = [
                'nivel' => self::NOTA,
                'texto' => 'La inversión ha bajado ' . self::pct($varCoste) . ' respecto a la semana anterior, '
                    . 'así que parte de la bajada de leads viene de ahí y no del rendimiento de las campañas.',
            ];
        }

        if (! $out) {
            $out[] = [
                'nivel' => self::BIEN,
                'texto' => 'La semana se ha movido en línea con la anterior: no hay ninguna métrica que se '
                    . 'haya desviado lo suficiente como para pedir un cambio.',
            ];
        }

        return $out;
    }

    /**
     * Por qué ha subido el CPL, deducido solo de cómo se han movido las dos
     * cifras que lo componen. No se afirma nada que no esté en los números.
     */
    private static function porQueSubeElCpl(array $a, array $b): string
    {
        $coste = self::variacion($a['coste'], $b['coste']) ?? 0.0;
        $leads = self::variacion($a['leads'], $b['leads']) ?? 0.0;

        if ($coste > self::LEVE && $leads < -self::LEVE) {
            return 'Se ha invertido más y han entrado menos leads, que es la peor de las combinaciones.';
        }

        if ($leads < -self::LEVE && abs($coste) <= self::LEVE) {
            return 'Han entrado menos leads con una inversión parecida.';
        }

        if ($coste > self::LEVE && abs($leads) <= self::LEVE) {
            return 'Se ha invertido más y los leads se han quedado donde estaban.';
        }

        if ($coste > 0 && $leads > 0) {
            return 'Suben las dos cosas, pero la inversión sube más deprisa que los leads.';
        }

        return 'Viene de la relación entre lo invertido y los leads conseguidos.';
    }

    /**
     * Comentario del bloque de canales. `$canales` son las filas que arma
     * InformesSemanales: cada una con canal, coste, leads y cpl.
     *
     * @param  Collection<int,array>  $canales
     * @return list<array{nivel:string,texto:string}>
     */
    public static function canales(Collection $canales, array $total): array
    {
        if ($canales->isEmpty()) {
            return [];
        }

        $out = [];

        // Un canal que se lleva una parte apreciable del dinero y no devuelve
        // nada es el hallazgo más útil que se puede sacar de estas cifras.
        if ($total['coste'] > 0) {
            foreach ($canales as $c) {
                $peso = $c['coste'] / $total['coste'] * 100;

                if ($c['leads'] == 0 && $peso >= self::PESO_CANAL) {
                    $out[] = [
                        'nivel' => self::PROBLEMA,
                        'texto' => $c['canal'] . ' se ha llevado el ' . self::numero($peso) . ' % de la inversión ('
                            . self::euros($c['coste']) . ') y no ha generado ningún lead. Es el primer sitio '
                            . 'donde mirar esta semana.',
                    ];
                }
            }
        }

        // El canal más eficiente, solo si hay más de uno con leads: con uno
        // solo la frase no aporta nada.
        $conLeads = $canales->filter(fn ($c) => $c['leads'] > 0 && $c['cpl'] > 0);
        if ($conLeads->count() > 1) {
            $mejor = $conLeads->sortBy('cpl')->first();
            $peor  = $conLeads->sortByDesc('cpl')->first();

            $out[] = [
                'nivel' => self::BIEN,
                'texto' => $mejor['canal'] . ' es el canal más eficiente de la semana, con un coste por lead de '
                    . self::euros($mejor['cpl']) . '. El más caro es ' . $peor['canal'] . ', a '
                    . self::euros($peor['cpl']) . ' por lead.',
            ];
        }

        return $out;
    }

    /**
     * Comentario del embudo: dice en qué escalón se cae la gente, que es la
     * diferencia entre un problema de anuncio y un problema de web.
     *
     * @return list<array{nivel:string,texto:string}>
     */
    public static function embudo(array $a, array $b): array
    {
        if ($a['clics'] == 0) {
            return [];
        }

        $tasa = $a['leads'] / $a['clics'] * 100;

        if (! self::comparable($b) || $b['clics'] == 0) {
            return [[
                'nivel' => self::NOTA,
                'texto' => 'De cada 100 personas que entran en la web, contactan ' . self::numero($tasa) . '.',
            ]];
        }

        $tasaAnterior = $b['leads'] / $b['clics'] * 100;
        $var          = self::variacion($tasa, $tasaAnterior);

        if ($var !== null && $var <= -self::GRAVE) {
            return [[
                'nivel' => self::AVISO,
                'texto' => 'De cada 100 personas que entran en la web contactan ' . self::numero($tasa)
                    . ', cuando la semana pasada eran ' . self::numero($tasaAnterior) . '. La caída está '
                    . 'después del clic: las campañas traen gente, pero esa gente contacta menos. '
                    . 'Mira la página de destino antes que los anuncios.',
            ]];
        }

        if ($var !== null && $var >= self::GRAVE) {
            return [[
                'nivel' => self::BIEN,
                'texto' => 'De cada 100 personas que entran en la web contactan ' . self::numero($tasa)
                    . ', frente a ' . self::numero($tasaAnterior) . ' la semana pasada.',
            ]];
        }

        return [[
            'nivel' => self::NOTA,
            'texto' => 'De cada 100 personas que entran en la web, contactan ' . self::numero($tasa)
                . ' (la semana pasada, ' . self::numero($tasaAnterior) . ').',
        ]];
    }

    // --------------------------------------------------------------- admin

    /**
     * Diagnóstico del resumen interno. Aquí sí se habla de margen: es para el
     * account manager.
     *
     * @param  list<array>  $porCliente  filas con cliente, gasto, facturado, sin_margen
     * @return list<array{nivel:string,texto:string}>
     */
    public static function paraAdmin(array $total, array $porCliente): array
    {
        $out = [];

        $margen = $total['facturado'] - $total['gasto'];

        if ($total['gasto'] > 0 && $margen < 0) {
            $out[] = [
                'nivel' => self::PROBLEMA,
                'texto' => 'El margen de la semana es negativo (' . self::euros($margen) . '): se ha gastado '
                    . self::euros($total['gasto']) . ' en plataformas y se factura ' . self::euros($total['facturado'])
                    . '. Sin tramo la línea se factura a coste (margen 0), así que un negativo apunta a un porcentaje mal puesto o a un override que se quedó corto.',
            ];
        }

        $sinMargen = array_sum(array_column($porCliente, 'sin_margen'));
        if ($sinMargen > 0) {
            $clientes = array_values(array_unique(array_map(
                fn ($f) => $f['cliente'],
                array_filter($porCliente, fn ($f) => $f['sin_margen'] > 0)
            )));

            $out[] = [
                'nivel' => self::PROBLEMA,
                'texto' => 'Hay ' . self::euros($sinMargen) . ' de gasto que se factura a coste, sin margen, en: '
                    . implode(', ', $clientes) . '. Se arregla asignando el tramo en /admin → Líneas.',
            ];
        }

        foreach ($porCliente as $f) {
            $m = $f['facturado'] - $f['gasto'];

            // El margen negativo de un cliente con su tramo puesto es otra cosa:
            // un porcentaje mal calculado o un override que se quedó corto.
            if ($f['gasto'] > 0 && $m < 0 && $f['sin_margen'] == 0) {
                $out[] = [
                    'nivel' => self::AVISO,
                    'texto' => $f['cliente'] . ' cierra la semana con margen negativo (' . self::euros($m)
                        . ') teniendo tramos asignados. Conviene revisar el porcentaje y los overrides.',
                ];
            }
        }

        if (! $out) {
            $out[] = [
                'nivel' => self::BIEN,
                'texto' => 'Todas las cuentas con gasto esta semana tienen margen aplicado y positivo.',
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------- render

    /**
     * Los comentarios en HTML. Cada uno lleva una banda de color a la izquierda
     * hecha con una celda de tabla: en Outlook un `border-left` sobre un div no
     * se ve, y aquí sí.
     *
     * @param  list<array{nivel:string,texto:string}>  $comentarios
     */
    public static function html(array $comentarios): string
    {
        if (! $comentarios) {
            return '';
        }

        $html = '';

        foreach ($comentarios as $c) {
            $color = self::COLORES[$c['nivel']] ?? self::COLORES[self::NOTA];

            $html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 10px;">'
                . '<tr>'
                . '<td width="3" style="width:3px;background:' . $color . ';font-size:0;line-height:0;">&nbsp;</td>'
                . '<td style="padding:2px 0 2px 12px;font-size:14px;line-height:1.5;color:#374151;">'
                . e($c['texto'])
                . '</td>'
                . '</tr></table>';
        }

        return $html;
    }

    // ------------------------------------------------------------ formatos

    /** Si la semana anterior no tuvo actividad, no hay nada que comparar. */
    private static function comparable(array $b): bool
    {
        return $b['coste'] > 0 || $b['clics'] > 0 || $b['impresiones'] > 0;
    }

    /** Variación en %, o null si no se puede calcular (base a cero). */
    private static function variacion(float $actual, float $anterior): ?float
    {
        return $anterior == 0 ? null : ($actual - $anterior) / $anterior * 100;
    }

    private static function pct(float $v): string
    {
        return ($v >= 0 ? '+' : '−') . number_format(abs($v), 0, ',', '.') . ' %';
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }

    private static function numero(float $v): string
    {
        return number_format($v, $v == floor($v) ? 0 : 1, ',', '.');
    }
}

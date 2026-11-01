<?php

namespace App\Services\Informes;

use App\Models\Cliente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Comentarios del informe redactados por un modelo de lenguaje.
 *
 * POR QUÉ EXISTE, HABIENDO YA UN Diagnostico. Las reglas de Diagnostico eligen
 * entre una docena de frases fijas: la misma subida de CPL produce el mismo
 * texto para todos los clientes y para siempre. Esto redacta cada semana.
 *
 * LO QUE SIGUE SIENDO VERDAD: el modelo tampoco sabe POR QUÉ se movió una
 * cifra, porque eso no está en `metricas_diarias`. La diferencia es que un
 * modelo, si no se le prohíbe, rellena ese hueco con una causa verosímil. De
 * ahí las reglas del prompt y, sobre todo, la validación de cifras.
 *
 * TRES REDES, en este orden:
 *   1. Al modelo le llegan los hechos YA CALCULADOS, nunca los datos crudos.
 *   2. Se comprueba que cada número del texto esté en esos hechos. Si aparece
 *      uno que no está, se descarta el texto entero (y se registra, para poder
 *      ver si la comprobación es demasiado estricta).
 *   3. Si algo falla —API caída, clave ausente, validación— se devuelve null y
 *      el informe sale con las frases de Diagnostico. El lunes a las 09:00 no
 *      hay nadie mirando: el correo tiene que salir siempre.
 *
 * Las tablas y los gráficos del email NO pasan por aquí: salen de la base de
 * datos. El modelo solo escribe prosa.
 */
class ComentarioIa
{
    public function __construct(private Redactor $redactor) {}

    /** Si no está activada en .env, no se construye ni el prompt. */
    public static function activa(): bool
    {
        return (bool) config('services.informes.ia.activa');
    }

    /** Los bloques que redacta para el informe del cliente. */
    private const BLOQUES = ['kpis', 'canales', 'embudo'];

    /**
     * Los tres comentarios del informe del cliente, o null para usar las reglas.
     *
     * Cada bloque viene con su nivel, para que la banda de color del email siga
     * diciendo de un vistazo si algo va mal: ese aviso visual se perdería si la
     * IA solo devolviera prosa.
     *
     * @param  Collection<int,array>  $canales
     * @return array<string,array{nivel:string,texto:string}>|null
     */
    public function paraCliente(
        Cliente $cliente,
        array $actual,
        array $anterior,
        Collection $canales,
        Carbon $desde,
        Carbon $hasta,
        ?string $nota = null,
    ): ?array {
        if (! self::activa()) {
            return null;
        }

        $hechos = $this->hechosCliente($cliente, $actual, $anterior, $canales, $desde, $hasta, $nota);
        $claves = self::conNivel(self::BLOQUES);

        $texto = $this->redactor->redactar(
            self::SISTEMA_CLIENTE,
            "Escribe los comentarios del informe con estos datos:\n\n" . $hechos,
            self::esquema($claves),
        );

        $plano = $this->validado($texto, $hechos, $claves, $cliente->nombre);

        if (! $plano) {
            return null;
        }

        $out = [];

        foreach (self::BLOQUES as $bloque) {
            $out[$bloque] = [
                'nivel' => self::nivel($plano[$bloque . '_nivel']),
                'texto' => $plano[$bloque],
            ];
        }

        return $out;
    }

    /**
     * El diagnóstico del informe interno, o null para usar las reglas.
     *
     * @param  list<array>  $porCliente
     * @return array{nivel:string,texto:string}|null
     */
    public function paraAdmin(array $total, array $porCliente, Carbon $desde, Carbon $hasta): ?array
    {
        if (! self::activa()) {
            return null;
        }

        $hechos = $this->hechosAdmin($total, $porCliente, $desde, $hasta);
        $claves = self::conNivel(['diagnostico']);

        $texto = $this->redactor->redactar(
            self::SISTEMA_ADMIN,
            "Escribe el diagnóstico de la semana con estos datos:\n\n" . $hechos,
            self::esquema($claves),
        );

        $plano = $this->validado($texto, $hechos, $claves, 'resumen interno');

        return $plano ? [
            'nivel' => self::nivel($plano['diagnostico_nivel']),
            'texto' => $plano['diagnostico'],
        ] : null;
    }

    /**
     * Por cada bloque, su clave de texto y su clave de nivel.
     *
     * @param  list<string>  $bloques
     * @return list<string>
     */
    private static function conNivel(array $bloques): array
    {
        $claves = [];

        foreach ($bloques as $bloque) {
            $claves[] = $bloque;
            $claves[] = $bloque . '_nivel';
        }

        return $claves;
    }

    /** Un nivel que no reconozcamos se queda en gris, no en alarma. */
    private static function nivel(string $valor): string
    {
        return in_array($valor, [
            Diagnostico::PROBLEMA, Diagnostico::AVISO, Diagnostico::BIEN, Diagnostico::NOTA,
        ], true) ? $valor : Diagnostico::NOTA;
    }

    // ------------------------------------------------------------ validación

    /**
     * Comprueba que estén todas las claves y que no haya cifras inventadas.
     *
     * @param  array<string,string>|null  $texto
     * @param  list<string>  $claves
     * @return array<string,string>|null
     */
    private function validado(?array $texto, string $hechos, array $claves, string $quien): ?array
    {
        if (! $texto) {
            return null;
        }

        $out = [];

        foreach ($claves as $clave) {
            if (! isset($texto[$clave]) || ! is_string($texto[$clave])) {
                Log::warning('Informes: la IA no devolvió el bloque esperado.', [
                    'quien' => $quien, 'falta' => $clave,
                ]);

                return null;
            }

            $out[$clave] = trim($texto[$clave]);
        }

        $inventadas = self::cifrasInventadas(implode(' ', $out), $hechos);

        if ($inventadas) {
            // Un CPL inventado, en un email que lleva justo encima la tabla con
            // el CPL de verdad, es peor que no comentar nada.
            Log::warning('Informes: se descarta el comentario de IA por cifras que no están en los datos.', [
                'quien' => $quien, 'cifras' => $inventadas, 'texto' => $out,
            ]);

            return null;
        }

        return $out;
    }

    /**
     * Números del texto que no aparecen en los hechos, comparando con el
     * formato español normalizado (miles con punto, decimales con coma).
     *
     * @return list<string>
     */
    public static function cifrasInventadas(string $texto, string $hechos): array
    {
        return array_values(array_diff(self::cifras($texto), self::cifras($hechos)));
    }

    /** @return list<string> */
    private static function cifras(string $s): array
    {
        preg_match_all('/\d+(?:\.\d{3})*(?:,\d+)?/', $s, $m);

        return array_values(array_unique(array_map(
            static function (string $n): string {
                $n = str_replace('.', '', $n);  // separador de miles
                $n = str_replace(',', '.', $n); // separador decimal

                return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
            },
            $m[0],
        )));
    }

    // ---------------------------------------------------------------- hechos

    /**
     * Los hechos del cliente, como lista de etiqueta y valor. Es a la vez lo
     * que ve el modelo y la lista contra la que se validan sus cifras, así que
     * tiene que incluir TODO número que legítimamente pueda querer citar.
     *
     * @param  Collection<int,array>  $canales
     */
    private function hechosCliente(
        Cliente $cliente,
        array $actual,
        array $anterior,
        Collection $canales,
        Carbon $desde,
        Carbon $hasta,
        ?string $nota,
    ): string {
        $hayAnterior = $anterior['coste'] > 0 || $anterior['clics'] > 0 || $anterior['impresiones'] > 0;

        $lineas = [
            'CLIENTE: ' . $cliente->nombre,
            'SEMANA INFORMADA: ' . InformesSemanales::periodo($desde, $hasta),
            '',
            'ESTA SEMANA',
            '- Inversión: ' . self::euros($actual['coste']),
            '- Impresiones: ' . self::entero($actual['impresiones']),
            '- Clics: ' . self::entero($actual['clics']),
            '- CTR: ' . self::numero($actual['ctr']) . ' %',
            '- CPC: ' . self::euros($actual['cpc']),
            '- Leads: ' . self::numero($actual['leads']),
            '- CPL: ' . ($actual['leads'] > 0 ? self::euros($actual['cpl']) : 'sin leads, no hay CPL'),
            '- De cada 100 personas que entran en la web, contactan: '
                . ($actual['clics'] > 0
                    ? self::numero($actual['leads'] / $actual['clics'] * 100)
                    : 'sin clics, no se puede calcular'),
        ];

        if ($hayAnterior) {
            $lineas = array_merge($lineas, [
                '',
                'SEMANA ANTERIOR',
                '- Inversión: ' . self::euros($anterior['coste']),
                '- Impresiones: ' . self::entero($anterior['impresiones']),
                '- Clics: ' . self::entero($anterior['clics']),
                '- CTR: ' . self::numero($anterior['ctr']) . ' %',
                '- CPC: ' . self::euros($anterior['cpc']),
                '- Leads: ' . self::numero($anterior['leads']),
                '- CPL: ' . ($anterior['leads'] > 0 ? self::euros($anterior['cpl']) : 'sin leads, no hay CPL'),
                '- De cada 100 personas que entraban en la web, contactaban: '
                    . ($anterior['clics'] > 0
                        ? self::numero($anterior['leads'] / $anterior['clics'] * 100)
                        : 'sin clics, no se puede calcular'),
                '',
                'VARIACIÓN RESPECTO A LA SEMANA ANTERIOR',
                '- Inversión: ' . self::variacion($actual['coste'], $anterior['coste']),
                '- Impresiones: ' . self::variacion($actual['impresiones'], $anterior['impresiones']),
                '- Clics: ' . self::variacion($actual['clics'], $anterior['clics']),
                '- Leads: ' . self::variacion($actual['leads'], $anterior['leads']),
                '- CPL: ' . self::variacion($actual['cpl'], $anterior['cpl']),
                '- CTR: ' . self::variacion($actual['ctr'], $anterior['ctr']),
            ]);
        } else {
            $lineas[] = '';
            $lineas[] = 'NO HAY SEMANA ANTERIOR con la que comparar: es la primera con datos. Dilo así, '
                . 'no te inventes una comparación.';
        }

        $lineas[] = '';
        $lineas[] = 'POR CANAL';

        $total = (float) $canales->sum('coste');

        foreach ($canales as $c) {
            $parte = $total > 0 ? self::numero($c['coste'] / $total * 100) . ' %' : 'sin inversión';

            $lineas[] = '- ' . $c['canal'] . ': ' . self::euros($c['coste']) . ' de inversión ('
                . $parte . ' del total), ' . self::entero($c['clics']) . ' clics, '
                . self::numero($c['leads']) . ' leads, CPL '
                . ($c['leads'] > 0 ? self::euros($c['cpl']) : 'no calculable porque no hubo leads');
        }

        if ($canales->isEmpty()) {
            $lineas[] = '- (ningún canal con actividad)';
        }

        if ($nota !== null && trim($nota) !== '') {
            $lineas[] = '';
            $lineas[] = 'NOTA DEL EQUIPO (es contexto para ti; NO la repitas, ya sale aparte en el email):';
            $lineas[] = trim($nota);
        }

        return implode("\n", $lineas);
    }

    /** @param  list<array>  $porCliente */
    private function hechosAdmin(array $total, array $porCliente, Carbon $desde, Carbon $hasta): string
    {
        $margen = $total['facturado'] - $total['gasto'];

        $lineas = [
            'SEMANA INFORMADA: ' . InformesSemanales::periodo($desde, $hasta),
            '',
            'TOTALES DE LA AGENCIA',
            '- Gasto real en plataformas: ' . self::euros($total['gasto']),
            '- Facturado a clientes: ' . self::euros($total['facturado']),
            '- Margen: ' . self::euros($margen),
            '- Clics: ' . self::entero($total['clics']),
            '- Leads: ' . self::numero($total['leads']),
            '',
            'POR CLIENTE',
        ];

        foreach ($porCliente as $f) {
            $m = $f['facturado'] - $f['gasto'];

            $lineas[] = '- ' . $f['cliente'] . ': gasto ' . self::euros($f['gasto'])
                . ', facturado ' . self::euros($f['facturado'])
                . ', margen ' . self::euros($m)
                . ($f['sin_margen'] > 0
                    ? ', y ' . self::euros($f['sin_margen']) . ' de gasto SIN MARGEN (sin tramo vigente o en pausa: se factura a coste)'
                    : ', todo su gasto lleva margen');
        }

        if (! $porCliente) {
            $lineas[] = '- (ninguna cuenta con actividad esta semana)';
        }

        return implode("\n", $lineas);
    }

    // --------------------------------------------------------------- prompts

    private const SISTEMA_CLIENTE = <<<'TXT'
        Escribes el comentario del informe semanal de campañas que una agencia española de
        marketing envía por email a sus clientes de publicidad. Te doy las cifras ya calculadas.

        REGLAS QUE NO PUEDES ROMPER:

        1. NO INVENTES CIFRAS. Solo puedes escribir números que aparezcan literalmente en los datos
        que te doy. No los redondees, no los sumes, no calcules porcentajes nuevos, no cites fechas
        ni números de días. Si quieres hablar de algo para lo que no tienes la cifra, descríbelo con
        palabras. Un número que no esté en los datos invalida todo el comentario.

        2. NO AFIRMES CAUSAS. Los datos dicen QUÉ ha cambiado, nunca POR QUÉ. No digas que algo se
        debe a la competencia, a la estacionalidad, al creativo, a la segmentación, al presupuesto ni
        a nada parecido: no lo sabes. Puedes decir qué ha cambiado y qué conviene revisar.

        3. NO PROMETAS NADA. Ni resultados futuros, ni acciones concretas que la agencia vaya a hacer.

        4. Si hay una nota del equipo, tenla en cuenta para no contradecirla, pero no la repitas: ya
        se muestra aparte en el email.

        CÓMO ESCRIBIR:
        - Español de España, tratando al cliente de "tú".
        - Claro y directo. Lo tiene que entender alguien que no sabe de marketing.
        - Entre una y tres frases por bloque. Mejor corto que relleno.
        - Sin saludos, sin despedidas y sin titulares: solo el comentario.
        - Nada de "cabe destacar", "es importante señalar", "en el panorama actual" ni relleno
        parecido. Si una semana no tiene nada reseñable, dilo en una frase y ya está.

        LOS TRES BLOQUES:
        - kpis: las cifras generales y cómo se comparan con la semana anterior.
        - canales: el reparto entre canales. Si un canal se lleva inversión sin devolver leads, eso
        es lo más importante que puedes decir y va primero.
        - embudo: el recorrido de impresiones a clics a leads, y en qué escalón se pierde la gente.

        Cada bloque lleva además su nivel, que pinta una banda de color en el email:
        - problema: hay que actuar (dinero sin retorno, ningún lead, una caída fuerte).
        - aviso: conviene vigilarlo, aún no es grave.
        - bien: ha mejorado respecto a la semana anterior.
        - nota: es solo información, no hay nada que decidir.
        TXT;

    private const SISTEMA_ADMIN = <<<'TXT'
        Escribes el diagnóstico del resumen semanal INTERNO de una agencia española de
        marketing. Lo lee el account manager, no el cliente, así que aquí sí se habla de gasto real,
        de lo facturado y del margen.

        REGLAS QUE NO PUEDES ROMPER:

        1. NO INVENTES CIFRAS. Solo números que aparezcan literalmente en los datos. No los
        redondees, no los sumes, no calcules porcentajes nuevos ni cites fechas. Un número que no
        esté en los datos invalida todo el diagnóstico.

        2. NO AFIRMES CAUSAS que no estén en los datos.

        PRIORIDADES, en este orden:
        1. Gasto sin margen: líneas sin tramo vigente (o con el margen en pausa) que se facturan al
        cliente a coste, así que la agencia no gana nada con ellas. Nombra los clientes afectados y
        el importe.
        2. Margen negativo.
        3. Lo que haya que revisar esta semana.

        CÓMO ESCRIBIR: español de España, directo, al grano, para alguien que conoce las cuentas.
        Entre dos y cinco frases. Sin saludos ni relleno. Si todo está en orden, dilo en una frase.

        Devuelve además el nivel, que pinta una banda de color: "problema" si hay gasto sin margen
        o margen negativo, "aviso" si hay algo que vigilar, "bien" si todo está correcto, "nota" si
        es solo información.
        TXT;

    /**
     * @param  list<string>  $claves
     * @return array<string,mixed>
     */
    private static function esquema(array $claves): array
    {
        $propiedades = [];

        foreach ($claves as $clave) {
            $propiedades[$clave] = str_ends_with($clave, '_nivel')
                ? ['type' => 'string', 'enum' => [
                    Diagnostico::PROBLEMA, Diagnostico::AVISO, Diagnostico::BIEN, Diagnostico::NOTA,
                ]]
                : ['type' => 'string'];
        }

        return [
            'type'                 => 'object',
            'properties'           => $propiedades,
            'required'             => $claves,
            'additionalProperties' => false,
        ];
    }

    // -------------------------------------------------------------- formatos

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }

    private static function entero(int|float $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    private static function numero(float $v): string
    {
        return number_format($v, $v == floor($v) ? 0 : 2, ',', '.');
    }

    private static function variacion(float $actual, float $anterior): string
    {
        if ($anterior == 0) {
            return $actual > 0 ? 'no comparable, la semana anterior fue cero' : 'sin cambios, las dos a cero';
        }

        $pct = ($actual - $anterior) / $anterior * 100;

        return ($pct >= 0 ? 'sube un ' : 'baja un ') . number_format(abs($pct), 0, ',', '.') . ' %';
    }
}

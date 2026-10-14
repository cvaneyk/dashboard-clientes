<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de email editable desde /admin → Plantillas de email.
 *
 * Las variables se escriben entre llaves ({cliente}, {periodo}...) y se
 * sustituyen con strtr, no con Blade: el texto lo escribe un humano desde el
 * panel y no debe poder ejecutar código. Las variables de texto se escapan
 * aquí; las tablas son el único HTML que se inyecta tal cual.
 */
class PlantillaEmail extends Model
{
    public const INFORME_SEMANAL = 'informe_semanal';
    public const INFORME_ADMIN   = 'informe_admin';

    /** Variables disponibles por plantilla, con su descripción para el panel. */
    public const VARIABLES = [
        self::INFORME_SEMANAL => [
            'cliente'          => 'Nombre del cliente',
            'periodo'          => 'Semana, p. ej. 28/09 al 04/10/2026',
            'desde'            => 'Primer día (dd/mm/aaaa)',
            'hasta'            => 'Último día (dd/mm/aaaa)',
            'inversion'        => 'Inversión de la semana',
            'impresiones'      => 'Impresiones',
            'clics'            => 'Clics',
            'leads'            => 'Leads',
            'ctr'              => 'CTR',
            'cpc'              => 'Coste por clic',
            'cpl'              => 'Coste por lead',
            'var_inversion'    => 'Variación de inversión vs. semana anterior',
            'var_clics'        => 'Variación de clics vs. semana anterior',
            'var_leads'        => 'Variación de leads vs. semana anterior',
            'enlace_dashboard' => 'URL del dashboard del cliente',
            'tabla_kpis'       => 'Tabla de métricas con comparación (en su propia línea)',
            'tabla_canales'    => 'Tabla por canal (en su propia línea)',
        ],
        self::INFORME_ADMIN => [
            'periodo'       => 'Semana, p. ej. 28/09 al 04/10/2026',
            'desde'         => 'Primer día (dd/mm/aaaa)',
            'hasta'         => 'Último día (dd/mm/aaaa)',
            'gasto'         => 'Gasto real total en plataformas',
            'facturado'     => 'Total facturado a clientes',
            'margen'        => 'Facturado menos gasto real',
            'tabla_kpis'    => 'Tabla de totales (en su propia línea)',
            'tabla_cuentas' => 'Tabla por cuenta, con el gasto sin margen aplicado',
            'avisos'        => 'Errores de sincronización, gasto sin margen, clientes sin email',
        ],
    ];

    protected $table = 'plantillas_email';
    protected $fillable = ['clave', 'nombre', 'asunto', 'cuerpo'];

    public static function deClave(string $clave): self
    {
        return static::where('clave', $clave)->firstOrFail();
    }

    /**
     * @param  array<string,string>  $texto  variables de texto plano (se escapan en el cuerpo)
     * @param  array<string,string>  $html   variables que ya son HTML (tablas)
     * @return array{asunto:string,cuerpo:string}
     */
    public function render(array $texto, array $html = []): array
    {
        $llaves = fn (array $vars) => collect($vars)->flatMap(fn ($v, $k) => [
            '{' . $k . '}'     => (string) $v,
            // El editor codifica las llaves dentro de un href.
            '%7B' . $k . '%7D' => (string) $v,
        ])->all();

        $asunto = strtr($this->asunto, $llaves($texto));

        // El editor envuelve cada línea en <p>; una tabla dentro de un <p> es
        // HTML inválido, así que primero se quita ese envoltorio.
        $cuerpo = (string) $this->cuerpo;
        foreach (array_keys($html) as $k) {
            $cuerpo = preg_replace('#<p>\s*\{' . preg_quote($k, '#') . '\}\s*</p>#', '{' . $k . '}', $cuerpo);
        }

        $cuerpo = strtr($cuerpo, $llaves(array_map('e', $texto)) + $llaves($html));

        return ['asunto' => $asunto, 'cuerpo' => $cuerpo];
    }
}

<?php

use App\Models\PlantillaEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mete los comentarios y los gráficos en las plantillas de informe.
 *
 * SOLO SI NADIE LAS HA TOCADO. Se compara el cuerpo con el que sembró la
 * migración de octubre y, si no coincide byte a byte, se deja como está: el
 * texto de las plantillas lo escribe el account manager desde /admin y un
 * despliegue no puede pisárselo (ver CLAUDE.md, sección 8). Quien ya la hubiera
 * editado tendrá que añadir las variables nuevas a mano; las tiene listadas en
 * la propia pantalla de edición.
 *
 * Al dejar de coincidir tras la primera pasada, volver a ejecutarla no hace nada.
 */
return new class extends Migration
{
    /** El cuerpo tal y como lo dejó 2026_10_05_000001_informes_por_email.php. */
    private const ORIGINAL = [
        PlantillaEmail::INFORME_SEMANAL => '<p>Hola,</p>'
            . '<p>Te enviamos el resumen de tus campañas de la semana del <strong>{periodo}</strong>.</p>'
            . '<p>{tabla_kpis}</p>'
            . '<h3>Por canal</h3>'
            . '<p>{tabla_canales}</p>'
            . '<p>Puedes ver el detalle por campaña y día en tu portal: <a href="{enlace_dashboard}">{enlace_dashboard}</a></p>'
            . '<p>Un saludo.</p>',

        PlantillaEmail::INFORME_ADMIN => '<p>Resumen de todas las cuentas de la semana del <strong>{periodo}</strong>.</p>'
            . '<p>{tabla_kpis}</p>'
            . '<h3>Por cuenta</h3>'
            . '<p>{tabla_cuentas}</p>'
            . '<h3>Avisos</h3>'
            . '<p>{avisos}</p>',
    ];

    /** El cuerpo nuevo: cada bloque de cifras seguido de su comentario. */
    private const NUEVO = [
        PlantillaEmail::INFORME_SEMANAL => '<p>Hola,</p>'
            . '<p>Te enviamos el resumen de tus campañas de la semana del <strong>{periodo}</strong>.</p>'
            . '<p>{nota}</p>'
            . '<h3>La semana de un vistazo</h3>'
            . '<p>{tabla_kpis}</p>'
            . '<p>{comentario_kpis}</p>'
            . '<h3>El recorrido: de que se vea el anuncio a que te contacten</h3>'
            . '<p>{embudo}</p>'
            . '<p>{comentario_embudo}</p>'
            . '<h3>Por canal</h3>'
            . '<p>{grafico_canales}</p>'
            . '<p>{tabla_canales}</p>'
            . '<p>{comentario_canales}</p>'
            . '<h3>Cómo va evolucionando</h3>'
            . '<p>{grafico_semanas}</p>'
            . '<p>Puedes ver el detalle por campaña y día en tu portal: <a href="{enlace_dashboard}">{enlace_dashboard}</a></p>'
            . '<p>Un saludo.</p>',

        PlantillaEmail::INFORME_ADMIN => '<p>Resumen de todas las cuentas de la semana del <strong>{periodo}</strong>.</p>'
            . '<p>{tabla_kpis}</p>'
            . '<p>{diagnostico}</p>'
            . '<h3>Margen por cliente</h3>'
            . '<p>{grafico_clientes}</p>'
            . '<h3>Gasto de las últimas semanas</h3>'
            . '<p>{grafico_semanas}</p>'
            . '<h3>Por cuenta</h3>'
            . '<p>{tabla_cuentas}</p>'
            . '<h3>Avisos</h3>'
            . '<p>{avisos}</p>',
    ];

    public function up(): void
    {
        foreach (self::NUEVO as $clave => $cuerpo) {
            DB::table('plantillas_email')
                ->where('clave', $clave)
                ->where('cuerpo', self::ORIGINAL[$clave])
                ->update(['cuerpo' => $cuerpo, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::ORIGINAL as $clave => $cuerpo) {
            DB::table('plantillas_email')
                ->where('clave', $clave)
                ->where('cuerpo', self::NUEVO[$clave])
                ->update(['cuerpo' => $cuerpo, 'updated_at' => now()]);
        }
    }
};

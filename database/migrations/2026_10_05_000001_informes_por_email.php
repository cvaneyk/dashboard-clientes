<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Informes semanales por email.
 *
 * - clientes.email_informes: destinatarios del informe del cliente (uno o varios,
 *   separados por coma). informe_semanal lo activa; sin email no se envía nada.
 * - plantillas_email: textos editables desde /admin. Las plantillas las define
 *   el sistema (una por tipo de informe, identificadas por `clave`); el panel
 *   solo deja editarlas. Se crean aquí y no en DatabaseSeeder para que un
 *   despliegue nunca pise lo que haya escrito el account manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('email_informes', 500)->nullable()->after('color');
            $table->boolean('informe_semanal')->default(false)->after('email_informes');
            $table->timestamp('ultimo_informe_en')->nullable()->after('informe_semanal');
        });

        Schema::create('plantillas_email', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->string('nombre');
            $table->string('asunto');
            $table->longText('cuerpo');
            $table->timestamps();
        });

        $ahora = now();

        DB::table('plantillas_email')->insert([
            [
                'clave'      => 'informe_semanal',
                'nombre'     => 'Informe semanal del cliente',
                'asunto'     => 'Informe semanal de campañas · {cliente} · {periodo}',
                'cuerpo'     => '<p>Hola,</p>'
                    . '<p>Te enviamos el resumen de tus campañas de la semana del <strong>{periodo}</strong>.</p>'
                    . '<p>{tabla_kpis}</p>'
                    . '<h3>Por canal</h3>'
                    . '<p>{tabla_canales}</p>'
                    . '<p>Puedes ver el detalle por campaña y día en tu portal: <a href="{enlace_dashboard}">{enlace_dashboard}</a></p>'
                    . '<p>Un saludo,<br>El equipo de OliMedia</p>',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave'      => 'informe_admin',
                'nombre'     => 'Resumen semanal interno (administración)',
                'asunto'     => '[Interno] Resumen semanal de cuentas · {periodo}',
                'cuerpo'     => '<p>Resumen de todas las cuentas de la semana del <strong>{periodo}</strong>.</p>'
                    . '<p>{tabla_kpis}</p>'
                    . '<h3>Por cuenta</h3>'
                    . '<p>{tabla_cuentas}</p>'
                    . '<h3>Avisos</h3>'
                    . '<p>{avisos}</p>',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_email');

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['email_informes', 'informe_semanal', 'ultimo_informe_en']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio de modelo de facturación: de objetivo mensual a porcentaje sobre el
 * gasto real.
 *
 * ANTES: cada fila era un EVENTO ("desde el día X el objetivo del mes es 3.100 €")
 * y el motor repartía ese objetivo entre los días del mes.
 *
 * AHORA: cada fila es un TRAMO con fecha de inicio y fin, y un porcentaje que se
 * añade a lo que se ha pagado de verdad a la plataforma ese día:
 *     coste_cliente_dia = gasto_real_dia * (1 + porcentaje/100)
 *
 * Consecuencia asumida: los días que no caigan en ningún tramo se facturan a 0 €,
 * así que el histórico anterior queda a cero hasta que se le creen tramos.
 *
 * La tabla conserva su nombre para no arrastrar un renombrado por todo el
 * proyecto, pero sus filas ya NO son eventos puntuales: son intervalos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presupuesto_eventos', function (Blueprint $table) {
            $table->dropIndex(['linea_id', 'fecha_efectiva']);
        });

        Schema::table('presupuesto_eventos', function (Blueprint $table) {
            $table->date('desde')->nullable()->after('linea_id');
            $table->date('hasta')->nullable()->after('desde');   // null = sin fecha de fin
            $table->decimal('porcentaje', 8, 2)->default(0)->after('hasta');
        });

        // Lo que hubiera se convierte en un tramo abierto desde su fecha, para no
        // dejar filas huérfanas sin fecha de inicio. El porcentaje queda a 0 y
        // hay que revisarlo a mano: no se puede deducir de un importe fijo.
        DB::table('presupuesto_eventos')->update(['desde' => DB::raw('fecha_efectiva')]);

        Schema::table('presupuesto_eventos', function (Blueprint $table) {
            $table->dropColumn(['fecha_efectiva', 'objetivo_mes']);
            $table->index(['linea_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::table('presupuesto_eventos', function (Blueprint $table) {
            $table->dropIndex(['linea_id', 'desde']);
            $table->date('fecha_efectiva')->nullable();
            $table->decimal('objetivo_mes', 14, 2)->default(0);
        });

        DB::table('presupuesto_eventos')->update(['fecha_efectiva' => DB::raw('desde')]);

        Schema::table('presupuesto_eventos', function (Blueprint $table) {
            $table->dropColumn(['desde', 'hasta', 'porcentaje']);
            $table->index(['linea_id', 'fecha_efectiva']);
        });
    }
};

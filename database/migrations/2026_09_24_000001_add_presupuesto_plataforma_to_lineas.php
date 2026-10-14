<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto que tiene la campaña EN LA PLATAFORMA (el tope de gasto), guardado
 * como dato interno de referencia para que el account manager fije el objetivo
 * del cliente con criterio.
 *
 * OJO: esto NO es el presupuesto del portal. El que factura al cliente es
 * presupuesto_eventos.objetivo_mes, que lleva margen y lo decide la agencia.
 * Esta cifra es de la misma familia que gasto_real: uso interno, nunca se
 * enseña al cliente ni entra en el motor de precios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas', function (Blueprint $table) {
            $table->decimal('presupuesto_plataforma', 14, 2)->nullable()->after('nivel');
            $table->string('presupuesto_plataforma_tipo')->nullable()->after('presupuesto_plataforma');   // diario | total
            $table->string('presupuesto_plataforma_nivel')->nullable()->after('presupuesto_plataforma_tipo'); // campania | adsets
            $table->timestamp('presupuesto_plataforma_actualizado_en')->nullable()->after('presupuesto_plataforma_nivel');
        });
    }

    public function down(): void
    {
        Schema::table('lineas', function (Blueprint $table) {
            $table->dropColumn([
                'presupuesto_plataforma',
                'presupuesto_plataforma_tipo',
                'presupuesto_plataforma_nivel',
                'presupuesto_plataforma_actualizado_en',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentario que el account manager escribe a mano para el informe semanal.
 *
 * Va acompañado de `comentario_semanal_semana`, el lunes de la semana a la que
 * se refiere: sin esa fecha, una nota escrita hace tres semanas seguiría
 * saliendo cada lunes como si fuera de esta. El informe solo la usa si coincide
 * con la semana que está informando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->text('comentario_semanal')->nullable()->after('ultimo_informe_en');
            $table->date('comentario_semanal_semana')->nullable()->after('comentario_semanal');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['comentario_semanal', 'comentario_semanal_semana']);
        });
    }
};

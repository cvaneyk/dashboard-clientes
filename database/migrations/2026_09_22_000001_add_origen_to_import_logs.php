<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue de dónde salió una importación: de un archivo subido a mano
 * ('manual') o de la sincronización por API ('api'). Lo existente es manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->string('origen')->default('manual')->after('plataforma');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};

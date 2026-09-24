<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué tipos de conversión cuentan como "lead" en esta cuenta concreta.
 *
 * Cada cliente mide distinto: unos con formularios de Lead Ads ('lead') y otros
 * con registros en su web vía píxel ('complete_registration'). Null = se usa la
 * lista por defecto de config/services.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->string('tipos_conversion')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropColumn('tipos_conversion');
        });
    }
};

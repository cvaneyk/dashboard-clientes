<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que necesita Google Ads en la tabla de conexiones.
 *
 * Meta guarda un token de vida larga y lo renueva canjeándolo. Google funciona
 * distinto: da un refresh_token que no caduca y con él se pide un access_token
 * nuevo cada hora. El access_token se sigue guardando en la columna de siempre
 * (con su expires_at) para no pedir uno en cada llamada.
 *
 * login_customer_id es la MCC desde la que se accede a la cuenta del cliente.
 * Null = se usa la de config/services.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conexiones_api', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->string('login_customer_id')->nullable()->after('refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('conexiones_api', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'login_customer_id']);
        });
    }
};

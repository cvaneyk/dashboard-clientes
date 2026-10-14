<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de API por Cuenta publicitaria (una conexión como máximo por cuenta).
 *
 * metodo = 'system_user' → token de System User pegado por el admin (no caduca,
 *           para cuentas que gestiona la agencia con su Business Manager).
 * metodo = 'oauth'       → el cliente conecta su propia cuenta vía Facebook Login;
 *           access_token de vida larga (~60 días) que el conector renova solo.
 *
 * El token se guarda CIFRADO (cast 'encrypted'): si alguien volcara la tabla,
 * no se llevaría tokens utilizables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexiones_api', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->unique()->constrained('cuentas')->cascadeOnDelete();
            $table->string('metodo'); // 'system_user' | 'oauth'
            $table->text('access_token');
            $table->timestamp('expires_at')->nullable();     // null = no caduca
            $table->timestamp('ultima_sincronizacion')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conexiones_api');
    }
};

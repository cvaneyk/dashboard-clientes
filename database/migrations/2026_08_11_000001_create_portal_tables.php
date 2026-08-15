<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal de clientes · Dashboard de campañas (Paid Media).
 * Modelo de datos base. Todas las cifras de dinero en decimal(14,2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clientes (cada uno tendrá su acceso y su dashboard).
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('color', 9)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Cuentas publicitarias por plataforma (un cliente puede tener varias).
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('plataforma');            // meta | google | tiktok | linkedin
            $table->string('nombre_cuenta');         // "Clínica Universitaria La Salle"
            $table->string('external_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Líneas de presupuesto (campaña o adset). Se emparejan con el CSV por match_key.
        Schema::create('lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->string('plataforma');
            $table->string('nombre');                // nombre visible: "INFANTIL_LEADADS"
            $table->string('producto')->nullable();  // "Infantil"
            $table->string('match_key');             // clave normalizada para casar con el CSV
            $table->string('nivel')->default('campania'); // campania | adset
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['cuenta_id', 'match_key']);
        });

        // Línea temporal del presupuesto-cliente (da la flexibilidad de subir/bajar/pausar en vivo).
        // objetivo_mes = total que se facturará al cliente ese mes, vigente desde fecha_efectiva.
        Schema::create('presupuesto_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linea_id')->constrained('lineas')->cascadeOnDelete();
            $table->date('fecha_efectiva');          // desde cuándo aplica
            $table->decimal('objetivo_mes', 14, 2)->default(0); // objetivo cliente para el mes
            $table->string('estado')->default('activa');        // activa | pausada
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['linea_id', 'fecha_efectiva']);
        });

        // Métricas REALES por día (lo que traen los CSV). El coste-cliente NO se guarda aquí:
        // se calcula con el motor de precios a partir de los presupuesto_eventos.
        Schema::create('metricas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->foreignId('linea_id')->nullable()->constrained('lineas')->nullOnDelete();
            $table->string('plataforma');
            $table->string('match_key')->nullable(); // por si aún no está enlazada a una línea
            $table->date('fecha');
            $table->unsignedBigInteger('impresiones')->default(0);
            $table->unsignedBigInteger('alcance')->default(0);
            $table->unsignedBigInteger('clics')->default(0);
            $table->decimal('gasto_real', 14, 2)->default(0); // lo que pagas tú a la plataforma
            $table->decimal('leads', 14, 2)->default(0);
            $table->json('extra')->nullable();       // otras columnas del CSV
            $table->foreignId('import_id')->nullable();
            $table->timestamps();

            // Una fila por línea y día (permite upsert al reimportar).
            $table->unique(['cuenta_id', 'match_key', 'fecha']);
            $table->index(['cliente_id', 'fecha']);
            $table->index(['linea_id', 'fecha']);
        });

        // Ajustes manuales del account manager (p. ej. tras un pico revisado).
        Schema::create('overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linea_id')->constrained('lineas')->cascadeOnDelete();
            $table->date('fecha')->nullable();       // null = aplica a todo el mes
            $table->string('campo');                 // coste_cliente | clics | leads | gasto_real
            $table->decimal('valor', 14, 2);
            $table->string('motivo')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['linea_id', 'fecha', 'campo']);
        });

        // Registro de importaciones (auditoría).
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('plataforma');
            $table->string('fichero')->nullable();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fin')->nullable();
            $table->unsignedInteger('filas_ok')->default(0);
            $table->unsignedInteger('filas_error')->default(0);
            $table->json('resumen')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
        Schema::dropIfExists('overrides');
        Schema::dropIfExists('metricas_diarias');
        Schema::dropIfExists('presupuesto_eventos');
        Schema::dropIfExists('lineas');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('clientes');
    }
};

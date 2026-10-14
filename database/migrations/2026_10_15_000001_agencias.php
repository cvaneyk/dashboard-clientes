<?php

use App\Models\Agencia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Varias agencias en una misma instalación.
 *
 * - agencias: marca, email del resumen interno y credenciales de sus apps de
 *   API (cifradas con APP_KEY, como los tokens de conexiones_api).
 * - clientes.agencia_id: de qué agencia es cada cliente. El resto de tablas
 *   cuelgan del cliente y se filtran a través de él.
 * - users.agencia_id + es_superadmin: el superadmin (quien opera la
 *   plataforma) ve todas las agencias y las da de alta.
 * - plantillas_email.agencia_id: cada agencia edita sus textos. Las filas sin
 *   agencia son las plantillas por defecto que se copian a cada agencia nueva.
 *
 * Si la instalación ya tenía datos, todo pasa a una primera agencia con la
 * marca (MARCA_*) y las credenciales que hubiera en el .env, y los admins de
 * entonces quedan como superadmins. Así nada cambia para quien ya la usaba.
 * Las restricciones de borrado son RESTRICT a propósito: una agencia con
 * clientes o usuarios se desactiva, no se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('slug')->unique();
            $table->boolean('activo')->default(true);
            $table->string('logo')->nullable();
            $table->string('logo_pie')->nullable();
            $table->string('web')->nullable();
            $table->string('eslogan')->nullable();
            $table->string('email_informes', 500)->nullable();
            $table->text('credenciales')->nullable();
            $table->timestamps();
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable()->after('id')
                ->constrained('agencias')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable()->after('id')
                ->constrained('agencias')->restrictOnDelete();
            $table->boolean('es_superadmin')->default(false)->after('es_admin');
        });

        Schema::table('plantillas_email', function (Blueprint $table) {
            $table->dropUnique(['clave']);
            $table->foreignId('agencia_id')->nullable()->after('id')
                ->constrained('agencias')->cascadeOnDelete();
            $table->unique(['agencia_id', 'clave']);
        });

        $this->moverDatosAUnaPrimeraAgencia();
    }

    private function moverDatosAUnaPrimeraAgencia(): void
    {
        $hayDatos = DB::table('clientes')->exists() || DB::table('users')->where('es_admin', true)->exists();

        if (! $hayDatos) {
            return;
        }

        $credenciales = [];
        foreach (Agencia::CREDENCIALES as $clave => $ruta) {
            if (filled(config($ruta))) {
                $credenciales[$clave] = (string) config($ruta);
            }
        }

        $nombre = (string) (config('marca.nombre') ?: 'Mi agencia');
        $ahora  = now();

        $agenciaId = DB::table('agencias')->insertGetId([
            'nombre'         => $nombre,
            'slug'           => Str::slug($nombre) ?: 'mi-agencia',
            'activo'         => true,
            // Los logos no se copian: en la agencia se suben desde el panel, y
            // MARCA_LOGO_URL sigue siendo el de la plataforma (página de login).
            'web'            => config('marca.web'),
            'eslogan'        => config('marca.eslogan'),
            'email_informes' => config('services.informes.admin'),
            // Mismo formato que el cast encrypted:array del modelo.
            'credenciales'   => $credenciales ? Crypt::encryptString(json_encode($credenciales)) : null,
            'created_at'     => $ahora,
            'updated_at'     => $ahora,
        ]);

        DB::table('clientes')->update(['agencia_id' => $agenciaId]);
        DB::table('users')->update(['agencia_id' => $agenciaId]);
        DB::table('users')->where('es_admin', true)->update(['es_superadmin' => true]);

        foreach (DB::table('plantillas_email')->whereNull('agencia_id')->get() as $p) {
            DB::table('plantillas_email')->insert([
                'agencia_id' => $agenciaId,
                'clave'      => $p->clave,
                'nombre'     => $p->nombre,
                'asunto'     => $p->asunto,
                'cuerpo'     => $p->cuerpo,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plantillas_email')->whereNotNull('agencia_id')->delete();

        Schema::table('plantillas_email', function (Blueprint $table) {
            $table->dropUnique(['agencia_id', 'clave']);
            $table->dropConstrainedForeignId('agencia_id');
            $table->unique('clave');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agencia_id');
            $table->dropColumn('es_superadmin');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agencia_id');
        });

        Schema::dropIfExists('agencias');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula usuarios a un cliente. Un usuario con cliente_id = X es un cliente
 * y solo ve SU dashboard. Los usuarios internos (account managers) llevan
 * es_admin = true y cliente_id = null (acceden al panel Filament).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->after('id')
                  ->constrained('clientes')->nullOnDelete();
            $table->boolean('es_admin')->default(false)->after('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropColumn('es_admin');
        });
    }
};

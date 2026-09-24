<?php

namespace App\Console\Commands\Concerns;

use App\Models\Cuenta;

/**
 * Cuando un comando no encuentra la cuenta que le han pedido, enseña las que sí
 * hay con su ID. Acertar el ID a ciegas desde el servidor es incómodo, y el
 * error a secas no dice si la cuenta no existe, es de otra plataforma o le
 * falta la conexión.
 */
trait MuestraCuentasMeta
{
    protected function mostrarCuentasMeta(): void
    {
        $cuentas = Cuenta::with(['cliente', 'conexionApi'])
            ->where('plataforma', 'meta')
            ->orderBy('id')
            ->get();

        if ($cuentas->isEmpty()) {
            $this->warn('No hay ninguna cuenta de Meta dada de alta. Se crean en /admin → Cuentas.');

            return;
        }

        $this->newLine();
        $this->line('Cuentas de Meta dadas de alta:');
        $this->table(
            ['ID', 'Cliente', 'Cuenta', 'Activa', 'API'],
            $cuentas->map(fn (Cuenta $cuenta) => [
                $cuenta->id,
                $cuenta->cliente?->nombre ?? '—',
                $cuenta->nombre_cuenta,
                $cuenta->activo ? 'sí' : 'NO',
                $cuenta->conexionApi ? 'conectada' : 'sin conectar',
            ])->all()
        );
    }

    /** Por qué una cuenta concreta no entra en la sincronización. */
    protected function motivoNoSincronizable(?Cuenta $cuenta): string
    {
        return match (true) {
            ! $cuenta                      => 'no existe ninguna cuenta con ese ID',
            $cuenta->plataforma !== 'meta' => "es de {$cuenta->plataforma}, y por ahora solo se sincroniza Meta",
            ! $cuenta->activo              => 'está marcada como inactiva en su ficha',
            ! $cuenta->conexionApi         => 'no tiene conexión con la API: conéctala desde su ficha en /admin',
            default                        => 'no cumple las condiciones para sincronizarse',
        };
    }
}

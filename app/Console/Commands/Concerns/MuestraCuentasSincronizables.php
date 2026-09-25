<?php

namespace App\Console\Commands\Concerns;

use App\Models\Cuenta;
use App\Services\Sync\Conectores;

/**
 * Cuando un comando no encuentra la cuenta que le han pedido, enseña las que sí
 * hay con su ID. Acertar el ID a ciegas desde el servidor es incómodo, y el
 * error a secas no dice si la cuenta no existe, es de una plataforma sin
 * conector o le falta la conexión.
 */
trait MuestraCuentasSincronizables
{
    /** @param  array<int,string>|null  $plataformas  null = todas las sincronizables */
    protected function mostrarCuentasSincronizables(?array $plataformas = null): void
    {
        $cuentas = Cuenta::with(['cliente', 'conexionApi'])
            ->whereIn('plataforma', $plataformas ?? Conectores::PLATAFORMAS)
            ->orderBy('id')
            ->get();

        if ($cuentas->isEmpty()) {
            $this->warn('No hay ninguna cuenta dada de alta para esas plataformas. Se crean en /admin → Cuentas.');

            return;
        }

        $this->newLine();
        $this->line('Cuentas dadas de alta:');
        $this->table(
            ['ID', 'Plataforma', 'Cliente', 'Cuenta', 'Activa', 'API'],
            $cuentas->map(fn (Cuenta $cuenta) => [
                $cuenta->id,
                Conectores::nombre($cuenta->plataforma),
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
            ! $cuenta => 'no existe ninguna cuenta con ese ID',
            ! Conectores::soportada($cuenta->plataforma) => sprintf(
                'es de %s, que todavía no tiene conector de API (se alimenta con informes a mano)',
                Conectores::nombre($cuenta->plataforma)
            ),
            ! $cuenta->activo      => 'está marcada como inactiva en su ficha',
            ! $cuenta->conexionApi => 'no tiene conexión con la API: conéctala desde su ficha en /admin',
            default                => 'no cumple las condiciones para sincronizarse',
        };
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Qué agencia "está mirando" en este momento. Es la ÚNICA fuente de verdad
 * del aislamiento entre agencias: la usan los global scopes de los modelos
 * (trait DeAgencia), el recurso de usuarios y los formularios.
 *
 *   - Sin usuario (scheduler, comandos, cola) o superadmin → sin filtro.
 *     Los procesos automáticos recorren todas las agencias a propósito y
 *     filtran ellos mismos cuando hace falta (p. ej. el resumen interno).
 *   - Cualquier otro usuario → solo su agencia. Un usuario sin agencia
 *     (instalación anterior a las agencias) ve solo los datos sin agencia,
 *     nunca los de una agencia concreta.
 *
 * `como()` fuerza una agencia durante un bloque: para tests y procesos que
 * quieran comportarse como un usuario de esa agencia.
 */
final class AgenciaActual
{
    private static bool $forzado = false;

    private static ?int $forzada = null;

    /** ¿Hay que filtrar por agencia? */
    public static function filtra(): bool
    {
        if (self::$forzado) {
            return true;
        }

        $user = Auth::user();

        return $user !== null && ! $user->es_superadmin;
    }

    /** ¿El usuario autenticado opera la plataforma (ve y gestiona todas las agencias)? */
    public static function esSuperadmin(): bool
    {
        return (bool) Auth::user()?->es_superadmin;
    }

    /** Agencia por la que filtrar (null = "sin agencia"). Solo tiene sentido si filtra(). */
    public static function id(): ?int
    {
        if (self::$forzado) {
            return self::$forzada;
        }

        return Auth::user()?->agencia_id;
    }

    /**
     * Ejecuta $fn como si mirara la agencia dada.
     *
     * @template T
     * @param  callable():T  $fn
     * @return T
     */
    public static function como(?int $agenciaId, callable $fn): mixed
    {
        [$antesForzado, $antesForzada] = [self::$forzado, self::$forzada];
        [self::$forzado, self::$forzada] = [true, $agenciaId];

        try {
            return $fn();
        } finally {
            [self::$forzado, self::$forzada] = [$antesForzado, $antesForzada];
        }
    }
}

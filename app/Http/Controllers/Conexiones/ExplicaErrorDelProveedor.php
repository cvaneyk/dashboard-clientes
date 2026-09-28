<?php

namespace App\Http\Controllers\Conexiones;

use Illuminate\Http\Request;

/**
 * Cuando una plataforma rechaza la autorización, vuelve al callback con
 * ?error= y ?error_description=. Ese texto es el ÚNICO sitio donde dice el
 * motivo real ("el scope no está autorizado para tu aplicación", "el usuario
 * no tiene acceso a la cuenta"…).
 *
 * Antes se sustituía por un "Conexión cancelada", que además de perder la
 * información era una suposición equivocada: casi nunca es una cancelación del
 * usuario, es un rechazo de la plataforma.
 */
trait ExplicaErrorDelProveedor
{
    protected function motivo(Request $request, string $plataforma, string $error): string
    {
        $descripcion = trim((string) $request->query('error_description'));

        // 'user_cancelled...' sí es una cancelación de verdad: se dice tal cual.
        if (str_contains(strtolower($error), 'cancel')) {
            return "Conexión cancelada desde {$plataforma}.";
        }

        $detalle = $descripcion !== '' ? $descripcion : $error;

        return "{$plataforma} rechazó la conexión: {$detalle}";
    }
}

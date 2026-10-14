<?php

namespace App\Http\Middleware;

use App\Models\Agencia;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tras autenticar, carga la marca y las apps de API de la agencia del usuario
 * en config(), que es de donde las leen las vistas, el panel y los conectores.
 *
 * Va detrás del middleware de autenticación (del portal y de Filament, también
 * en sus peticiones Livewire). Quien actúa sobre una cuenta de OTRA agencia
 * (el superadmin) recarga las credenciales de esa cuenta en el punto de uso:
 * Conectores::para() y los controladores de OAuth.
 */
class AplicarAgencia
{
    public function handle(Request $request, Closure $next): Response
    {
        $user    = Auth::user();
        $agencia = $user?->agencia_id ? Agencia::withoutGlobalScopes()->find($user->agencia_id) : null;

        // Sin agencia (un superadmin que no pertenece a ninguna) se queda la
        // marca y las apps de la plataforma: las del .env.
        if ($agencia) {
            $agencia->aplicarMarca();
            $agencia->usarCredenciales();
        }

        return $next($request);
    }
}

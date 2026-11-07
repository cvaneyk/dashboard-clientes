<?php

namespace App\Http\Controllers;

use App\Support\Manuales;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Manuales de conexión. Solo admins: describen el panel /admin, al que un
 * usuario de cliente no tiene acceso.
 */
class ManualesController extends Controller
{
    public function index(): View
    {
        abort_unless(Auth::user()?->es_admin, 403);

        return view('portal.manuales', [
            'manuales'   => Manuales::indice(),
            'plataforma' => null,
        ]);
    }

    public function show(string $plataforma): View
    {
        abort_unless(Auth::user()?->es_admin, 403);
        abort_unless(Manuales::existe($plataforma), 404);

        return view('portal.manuales', [
            'manuales'   => Manuales::indice(),
            'plataforma' => $plataforma,
            'manual'     => Manuales::render($plataforma),
        ]);
    }
}

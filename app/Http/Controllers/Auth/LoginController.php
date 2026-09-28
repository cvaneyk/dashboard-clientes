<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Login sencillo para el PORTAL DE CLIENTES (separado del panel Filament).
 * Los usuarios internos (es_admin) se redirigen a /admin.
 */
class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->destino();
        }

        return view('portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $cred = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($cred, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Credenciales incorrectas.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return $this->destino();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    /** Envía a cada usuario a su sitio: admin → Filament; cliente → dashboard. */
    protected function destino(): RedirectResponse
    {
        $user = Auth::user();

        if ($user->es_admin) {
            // Este login autentica el guard 'web'; el panel usa el guard 'admin'.
            // Sin esto, un admin que entra por /login llega a /admin y tiene que
            // volver a identificarse con las mismas credenciales que acaba de dar.
            // Los guards siguen separados: son dos sesiones, no una compartida.
            if (! Auth::guard('admin')->check()) {
                Auth::guard('admin')->login($user);
            }

            return redirect('/admin');
        }

        if (! $user->cliente_id) {
            Auth::logout();
            return redirect()->route('portal.login')
                ->withErrors(['email' => 'Este usuario no tiene un cliente asignado.']);
        }

        return redirect()->route('portal.dashboard');
    }
}

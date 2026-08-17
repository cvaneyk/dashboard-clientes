<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * "Mi cuenta" del cliente: cambio de contraseña desde su propio dashboard.
 */
class AccountController extends Controller
{
    public function edit(): View
    {
        return view('portal.cuenta', ['cliente' => Auth::user()->cliente]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current'  => ['required'],
            'password' => ['required', 'confirmed', 'min:8'],
        ], [], [
            'current'  => 'contraseña actual',
            'password' => 'nueva contraseña',
        ]);

        $user = Auth::user();

        if (! Hash::check($request->input('current'), $user->password)) {
            return back()->withErrors(['current' => 'La contraseña actual no es correcta.']);
        }

        $user->password = $request->input('password'); // el cast "hashed" la cifra
        $user->save();

        return back()->with('ok', 'Contraseña actualizada correctamente.');
    }
}

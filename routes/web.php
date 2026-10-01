<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Conexiones\GoogleConexionController;
use App\Http\Controllers\Conexiones\LinkedInConexionController;
use App\Http\Controllers\Conexiones\MetaConexionController;
use App\Http\Controllers\Conexiones\SpotifyConexionController;
use App\Http\Controllers\DashboardController;
use App\Models\Cliente;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Logo del cliente (servido desde el disco público, sin necesidad de storage:link).
Route::get('/media/logo/{cliente}', function (Cliente $cliente) {
    abort_unless($cliente->logo && ! str_starts_with($cliente->logo, 'http'), 404);
    $disk = Storage::disk('public');
    abort_unless($disk->exists($cliente->logo), 404);

    return response()->file($disk->path($cliente->logo));
})->name('cliente.logo');

// Login del portal de clientes.
Route::get('/login', [LoginController::class, 'show'])->name('portal.login');
Route::post('/login', [LoginController::class, 'login'])->name('portal.login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('portal.logout');

// Zona privada del cliente.
//
// Acepta los DOS guards a propósito: el cliente entra por /login (guard 'web')
// y el admin por el panel de Filament (guard 'admin'), y este último también
// tiene que poder abrir el dashboard para verlo como lo ve el cliente. Los
// guards siguen separados —sesiones independientes, que es lo que arregló el
// 403—; lo único que se hace aquí es admitir cualquiera de los dos.
Route::middleware('auth:web,admin')->group(function () {
    Route::get('/', function () {
        // Los admin van a Filament; los clientes al dashboard.
        return Auth::user()->es_admin
            ? redirect('/admin')
            : redirect()->route('portal.dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('portal.dashboard');

    // Misma pantalla con el gasto real de plataforma en vez del coste
    // facturado. Solo admins: el propio controlador aborta si no lo es.
    Route::get('/dashboard/interno', [DashboardController::class, 'interno'])
        ->name('portal.dashboard.interno');

    // Mi cuenta (cambio de contraseña del cliente).
    Route::get('/cuenta', [AccountController::class, 'edit'])->name('portal.cuenta');
    Route::post('/cuenta', [AccountController::class, 'update'])->name('portal.cuenta.update');
});

// Conexión OAuth de las APIs de anuncios: la lanza el admin desde la ficha de
// una Cuenta y la plataforma vuelve al callback. Guard 'admin' porque la sesión
// de la conexión viaja con el admin que la inicia.
Route::middleware('auth:admin')->group(function () {
    Route::get('/conexiones/meta/autorizar', [MetaConexionController::class, 'autorizar'])
        ->name('conexiones.meta.autorizar');
    Route::get('/conexiones/meta/callback', [MetaConexionController::class, 'callback'])
        ->name('conexiones.meta.callback');

    Route::get('/conexiones/google/autorizar', [GoogleConexionController::class, 'autorizar'])
        ->name('conexiones.google.autorizar');
    Route::get('/conexiones/google/callback', [GoogleConexionController::class, 'callback'])
        ->name('conexiones.google.callback');

    Route::get('/conexiones/linkedin/autorizar', [LinkedInConexionController::class, 'autorizar'])
        ->name('conexiones.linkedin.autorizar');
    Route::get('/conexiones/linkedin/callback', [LinkedInConexionController::class, 'callback'])
        ->name('conexiones.linkedin.callback');

    Route::get('/conexiones/spotify/autorizar', [SpotifyConexionController::class, 'autorizar'])
        ->name('conexiones.spotify.autorizar');
    Route::get('/conexiones/spotify/callback', [SpotifyConexionController::class, 'callback'])
        ->name('conexiones.spotify.callback');
});

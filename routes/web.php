<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
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
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        // Los admin van a Filament; los clientes al dashboard.
        return Auth::user()->es_admin
            ? redirect('/admin')
            : redirect()->route('portal.dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('portal.dashboard');

    // Mi cuenta (cambio de contraseña del cliente).
    Route::get('/cuenta', [AccountController::class, 'edit'])->name('portal.cuenta');
    Route::post('/cuenta', [AccountController::class, 'update'])->name('portal.cuenta.update');
});

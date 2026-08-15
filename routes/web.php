<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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
});

<?php

namespace App\Providers;

use App\Services\Informes\Redactor;
use App\Services\Informes\RedactorClaude;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Quien redacta los comentarios del informe semanal. Los tests
        // sustituyen este enlace por un doble, para no llamar a la API.
        $this->app->bind(Redactor::class, RedactorClaude::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Los invitados al área privada van al login del portal.
        $middleware->redirectGuestsTo('/login');

        // En Plesk el SSL termina en el proxy y a PHP le llega la petición como
        // HTTP plano: sin esto Laravel genera URLs absolutas con http:// aunque
        // el sitio sea https. Se nota en los redirect_uri de OAuth, que las
        // plataformas comparan carácter por carácter y rechazan.
        // '*' es lo correcto aquí porque el backend PHP solo es accesible a
        // través del proxy, nunca directamente desde fuera.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

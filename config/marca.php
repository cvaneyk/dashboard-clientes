<?php

/*
|--------------------------------------------------------------------------
| Marca de la instalación
|--------------------------------------------------------------------------
|
| Identidad de quien opera el portal (la agencia), no la de cada cliente: el
| cliente tiene su propio logo y color en su ficha. Todo sale del .env para
| que una misma base de código sirva a varias instalaciones sin tocar vistas.
|
| Sin logo se muestra el nombre en texto; sin web, el pie no lleva enlace.
|
*/

return [

    'nombre' => env('MARCA_NOMBRE', 'Portal de clientes'),

    // URL absoluta (https://...) o ruta dentro de public/ (/img/logo.png).
    'logo' => env('MARCA_LOGO_URL'),

    // Versión para el pie, sobre fondo oscuro. Si falta se usa el logo normal.
    'logo_pie' => env('MARCA_LOGO_PIE_URL'),

    'web' => env('MARCA_WEB'),

    // Frase corta tras el nombre en el pie: "© 2026 Nombre · {eslogan}."
    'eslogan' => env('MARCA_ESLOGAN'),

    // Usuario admin que crea DatabaseSeeder en una instalación nueva.
    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),

];

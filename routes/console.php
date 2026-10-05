<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Sincronización diaria por API: a las 07:30 (hora española) las plataformas
 * ya han cerrado los datos del día anterior, y la ventana de varios días del
 * conector absorbe las correcciones tardías.
 *
 * Requiere en el VPS de Plesk un cron cada minuto que ejecute:
 *   php artisan schedule:run
 * (ver docs/DESPLIEGUE.md). Si no existe el cron, la sincronización hay que
 * lanzarla a mano con "php artisan portal:sync" o con el botón de la cuenta.
 */
Schedule::command('portal:sync')
    ->dailyAt('07:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping();

/*
 * Informes semanales por email (semana lunes-domingo anterior): el de cada
 * cliente con el informe activado en su ficha y el resumen interno de todas
 * las cuentas para administración. A las 09:00, cuando la sincronización de
 * las 07:30 ya ha traído el domingo. Necesita el SMTP configurado en .env.
 */
Schedule::command('portal:informes')
    ->weeklyOn(1, '09:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping();

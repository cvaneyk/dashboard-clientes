<?php

namespace Tests\Fakes;

use App\Services\Informes\Redactor;

/**
 * Redactor de pruebas: devuelve lo que se le diga y apunta lo que recibió.
 *
 * Los tests no pueden llamar a la API de verdad (costaría dinero y fallaría sin
 * red), y además hay que poder comprobar QUÉ se le manda al modelo: que en los
 * hechos del informe del cliente no viaje nunca el gasto real.
 */
class RedactorFalso implements Redactor
{
    /** Lo que devolverá la próxima llamada; null simula un fallo de la API. */
    public static ?array $respuesta = null;

    public static ?string $sistema = null;
    public static ?string $peticion = null;
    public static int $llamadas = 0;

    public static function reiniciar(): void
    {
        self::$respuesta = null;
        self::$sistema   = null;
        self::$peticion  = null;
        self::$llamadas  = 0;
    }

    public function redactar(string $sistema, string $peticion, array $esquema): ?array
    {
        self::$sistema  = $sistema;
        self::$peticion = $peticion;
        self::$llamadas++;

        return self::$respuesta;
    }
}

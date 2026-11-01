<?php

namespace App\Services\Informes;

/**
 * Quien redacta los comentarios del informe con un modelo de lenguaje.
 *
 * Existe como interfaz por una razón concreta: los tests no pueden llamar a la
 * API de verdad (costaría dinero y fallaría sin red), así que sustituyen esta
 * pieza por una que devuelve un texto fijo. Toda la conversación con Anthropic
 * vive en RedactorClaude y en ningún otro sitio.
 */
interface Redactor
{
    /**
     * Pide un texto al modelo, con la forma que describe el esquema.
     *
     * Devuelve null —y no lanza— cuando no se ha podido redactar: sin clave, con
     * la API caída, por tiempo de espera o porque la respuesta no encaja. El
     * informe tiene que salir igual, así que quien llama cae a las reglas.
     *
     * @param  array<string,mixed>  $esquema  JSON Schema de la respuesta
     * @return array<string,string>|null
     */
    public function redactar(string $sistema, string $peticion, array $esquema): ?array;
}

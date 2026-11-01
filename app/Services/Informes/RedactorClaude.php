<?php

namespace App\Services\Informes;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redactor contra la API de Anthropic, con el SDK oficial (anthropic-ai/sdk).
 *
 * OJO AL DESPLIEGUE: el deploy de Plesk solo ejecuta migrate, db:seed y
 * optimize:clear (ver docs/DESPLIEGUE.md), así que `vendor/` no se actualiza
 * sola. Tras el primer push con esta dependencia hay que entrar al VPS y
 * ejecutar `composer install` una vez. Si no se hace, la clase no existe, se
 * captura el Error aquí mismo y el informe sale con los comentarios por reglas.
 *
 * Se pide **salida estructurada** (output_config.format) en lugar de confiar en
 * que el modelo devuelva un JSON bien formado por su cuenta.
 */
class RedactorClaude implements Redactor
{
    private string $esfuerzo = 'medium';

    public function __construct(
        private ?string $clave = null,
        private ?string $modelo = null,
    ) {
        $this->clave ??= config('services.informes.ia.clave');
        $this->modelo ??= config('services.informes.ia.modelo') ?: 'claude-opus-5-5';
    }

    public function redactar(string $sistema, string $peticion, array $esquema): ?array
    {
        if (! $this->clave) {
            Log::warning('Informes: falta ANTHROPIC_API_KEY, se usan los comentarios por reglas.');

            return null;
        }

        try {
            $cliente = new Client(apiKey: $this->clave);

            $respuesta = $cliente->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $peticion]],
                model: $this->modelo,
                // Opus 5.5 lleva el razonamiento siempre activo y su esfuerzo por
                // defecto es 'medium'; se fija explícitamente para que un cambio
                // en ese valor por defecto no mueva el coste sin avisar.
                outputConfig: [
                    'effort' => $this->esfuerzo,
                    'format' => ['type' => 'json_schema', 'schema' => $esquema],
                ],
                system: $sistema,
            );

            // Los clasificadores de seguridad pueden declinar la petición: llega
            // un 200 con stopReason 'refusal' y sin texto aprovechable.
            if ($respuesta->stopReason === 'refusal') {
                Log::warning('Informes: el modelo declinó redactar el comentario.', [
                    'categoria' => $respuesta->stopDetails?->category,
                ]);

                return null;
            }

            foreach ($respuesta->content as $bloque) {
                if ($bloque->type !== 'text') {
                    continue; // los bloques de razonamiento van delante del texto
                }

                $datos = json_decode($bloque->text, true);

                return is_array($datos) ? $datos : null;
            }

            return null;
        } catch (Throwable $e) {
            // Cualquier fallo —red, clave inválida, límite de peticiones, SDK sin
            // instalar— se registra y se devuelve null: el informe sale con las
            // frases por reglas antes que no salir.
            Log::error('Informes: no se pudo redactar el comentario con IA.', [
                'excepcion' => $e::class,
                'mensaje'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}

<?php

namespace App\Support;

use App\Models\Cuenta;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Manuales de conexión de cada plataforma, para los admins de las agencias.
 *
 * El texto vive en resources/manuales/{plataforma}.md, versionado con el código
 * que describe: si cambia un botón o un mensaje del panel, el manual se cambia
 * en el mismo commit. La lista de plataformas sale de Cuenta::PLATAFORMAS, así
 * que una plataforma nueva sin manual hace fallar ManualesTest.
 *
 * Los .md usan el marcador {url_retorno} para la URL de retorno OAuth de la
 * plataforma, porque cada despliegue tiene su dominio.
 */
class Manuales
{
    /** Qué es cada manual, en una línea, para el índice. */
    private const RESUMENES = [
        'google'   => 'API con la cuenta del cliente o con la MCC de la agencia, y subida de informes.',
        'meta'     => 'API con token de System User o con la cuenta del cliente, y subida de informes.',
        'linkedin' => 'API con el acceso de un usuario a la cuenta publicitaria. Requiere aprobación de LinkedIn.',
        'spotify'  => 'API con el acceso de un usuario de Ads Manager.',
        'openai'   => 'API con una clave por cuenta publicitaria. Es la conexión más sencilla.',
        'tiktok'   => 'Sin API por ahora: cómo exportar y subir el informe.',
    ];

    public static function existe(string $plataforma): bool
    {
        return array_key_exists($plataforma, Cuenta::PLATAFORMAS);
    }

    /** @return array<string,array{nombre:string,resumen:string}> */
    public static function indice(): array
    {
        $indice = [];

        foreach (Cuenta::PLATAFORMAS as $clave => $nombre) {
            $indice[$clave] = [
                'nombre'  => $nombre,
                'resumen' => self::RESUMENES[$clave] ?? '',
            ];
        }

        return $indice;
    }

    /**
     * El manual ya en HTML, con los títulos de sección identificados para el
     * índice lateral. El Markdown es nuestro, del repo: nunca viene de un usuario.
     *
     * @return array{html:string,secciones:array<string,string>}
     */
    public static function render(string $plataforma): array
    {
        $texto = (string) file_get_contents(resource_path("manuales/{$plataforma}.md"));

        $html = Str::markdown(strtr($texto, ['{url_retorno}' => self::urlRetorno($plataforma)]));

        // Los enlaces externos abren aparte: quien sigue un manual necesita
        // tener a la vista los pasos mientras trabaja en la otra plataforma.
        $html = str_replace('<a href="http', '<a target="_blank" rel="noopener" href="http', $html);

        $secciones = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $m) use (&$secciones): string {
            $id             = Str::slug(strip_tags($m[1]));
            $secciones[$id] = strip_tags($m[1]);

            return sprintf('<h2 id="%s">%s</h2>', $id, $m[1]);
        }, $html);

        return ['html' => $html, 'secciones' => $secciones];
    }

    private static function urlRetorno(string $plataforma): string
    {
        $ruta = "conexiones.{$plataforma}.callback";

        return Route::has($ruta) ? route($ruta) : '';
    }
}

<?php

namespace App\Services\Sync;

use App\Models\Cuenta;
use RuntimeException;

/**
 * Elige el conector que toca según la plataforma de la Cuenta.
 *
 * Añadir TikTok o LinkedIn es escribir su conector, que implemente Conector, y
 * apuntarlo aquí: ni el comando ni el panel se enteran.
 */
class Conectores
{
    /** Plataformas con sincronización por API disponible. */
    public const PLATAFORMAS = ['meta', 'google'];

    public static function para(Cuenta $cuenta): Conector
    {
        return match ($cuenta->plataforma) {
            'meta'   => app(MetaConnector::class),
            'google' => app(GoogleAdsConnector::class),
            default  => throw new RuntimeException(
                "Todavía no hay sincronización por API para {$cuenta->plataforma}: esa cuenta se alimenta con informes subidos a mano."
            ),
        };
    }

    public static function soportada(?string $plataforma): bool
    {
        return in_array($plataforma, self::PLATAFORMAS, true);
    }

    /** Nombre presentable de la plataforma, para mensajes y botones. */
    public static function nombre(?string $plataforma): string
    {
        return match ($plataforma) {
            'meta'     => 'Meta',
            'google'   => 'Google Ads',
            'tiktok'   => 'TikTok',
            'linkedin' => 'LinkedIn',
            default    => (string) $plataforma,
        };
    }
}

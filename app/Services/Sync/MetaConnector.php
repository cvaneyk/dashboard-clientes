<?php

namespace App\Services\Sync;

use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Services\Import\CampaignImporter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Conector con la Marketing API de Meta (Graph API).
 *
 * Baja los insights de campaña día a día y los vuelca como filas normalizadas
 * en CampaignImporter::importRows(), que es el mismo destino que la subida
 * manual de informes. Por eso sincronizar por API y subir el Excel a mano son
 * intercambiables e idempotentes entre sí.
 *
 * Dos métodos de autenticación, elegidos por cuenta desde /admin:
 *   - 'system_user' → token de System User del Business Manager de OliMedia.
 *     No caduca; vale para las cuentas que la agencia gestiona.
 *   - 'oauth' → el cliente conecta su propia cuenta con Facebook Login.
 *     Da un token de vida larga (~60 días) que se renueva aquí solo cuando
 *     toca, sin que nadie tenga que volver a hacer login.
 *
 * La app de Facebook (META_APP_ID / META_APP_SECRET) solo es necesaria para
 * el método OAuth.
 */
class MetaConnector
{
    private const SCOPE = 'ads_read';

    /** Tipos de acción que cuentan como lead (la columna "Registros completados" del informe). */
    private const TIPOS_LEAD = ['lead'];

    public function __construct(private CampaignImporter $importer)
    {
    }

    public function appConfigurada(): bool
    {
        return (bool) config('services.meta.app_id') && (bool) config('services.meta.app_secret');
    }

    /**
     * URL del diálogo de Facebook Login. El estado (nonce) viaja de ida y
     * vuelta para verificar en el callback que la petición la iniciamos
     * nosotros (anti-CSRF).
     */
    public function urlAutorizacion(string $estado, string $redirectUri): string
    {
        return sprintf(
            'https://www.facebook.com/%s/dialog/oauth?%s',
            config('services.meta.api_version'),
            http_build_query([
                'client_id'     => config('services.meta.app_id'),
                'redirect_uri'  => $redirectUri,
                'state'         => $estado,
                'response_type' => 'code',
                'scope'         => self::SCOPE,
            ])
        );
    }

    /**
     * Canjea el `code` del callback por un token de vida larga y comprueba que
     * ese usuario puede leer la cuenta publicitaria de la Cuenta del portal
     * (act_{external_id}). Si todo encaja, crea (o sustituye) la conexión.
     */
    public function guardarTokenOauth(Cuenta $cuenta, string $code, string $redirectUri): ConexionApi
    {
        $tokenCorto = $this->get('oauth/access_token', [
            'client_id'     => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ])['access_token'] ?? null;

        if (! $tokenCorto) {
            throw new RuntimeException('Meta no devolvió token al canjear el código de autorización.');
        }

        $larga = $this->get('oauth/access_token', [
            'client_id'     => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'grant_type'    => 'fb_exchange_token',
            'fb_exchange_token' => $tokenCorto,
        ]);

        $token  = (string) ($larga['access_token'] ?? '');
        $segundos = (int) ($larga['expires_in'] ?? 0);

        if ($token === '') {
            throw new RuntimeException('Meta no devolvió el token de vida larga.');
        }

        // ¿El usuario que acaba de autorizar ve la cuenta publicitaria que
        // representa esta Cuenta? Si no, la conexión no serviría de nada.
        $this->get('act_' . $this->cuentaExternaId($cuenta), ['fields' => 'name'], $token);

        return ConexionApi::updateOrCreate(
            ['cuenta_id' => $cuenta->id],
            [
                'metodo'     => 'oauth',
                'access_token' => $token,
                'expires_at' => $segundos > 0 ? now()->addSeconds($segundos) : null,
                'ultimo_error' => null,
            ]
        );
    }

    /**
     * Los tokens OAuth de Meta duran ~60 días, pero canjear un token válido
     * por otro nuevo reinicia el contador. Lo hacemos cuando quedan menos de
     * 7 días, de modo que la sincronización diaria mantenga la conexión viva
     * indefinidamente sin pasar por el login otra vez.
     */
    public function renovarSiProcede(ConexionApi $conexion): void
    {
        if ($conexion->metodo !== 'oauth' || ! $conexion->expires_at) {
            return;
        }

        if ($conexion->expires_at->isFuture() && $conexion->expires_at->gt(now()->addDays(7))) {
            return;
        }

        $nueva = $this->get('oauth/access_token', [
            'client_id'     => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'grant_type'    => 'fb_exchange_token',
            'fb_exchange_token' => $conexion->access_token,
        ]);

        $segundos = (int) ($nueva['expires_in'] ?? 0);

        $conexion->forceFill([
            'access_token' => (string) ($nueva['access_token'] ?? $conexion->access_token),
            'expires_at'   => $segundos > 0 ? now()->addSeconds($segundos) : null,
        ])->save();
    }

    /**
     * Sincroniza los últimos $dias días de la cuenta y los importa.
     * Se re-bajan varios días a propósito: las plataformas corrigen cifras
     * hacia atrás, y el upsert idempotente lo absorbe sin duplicar.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizar(Cuenta $cuenta, int $dias = 3): array
    {
        $conexion = $cuenta->conexionApi;

        if (! $conexion) {
            throw new RuntimeException('Esta cuenta no tiene conexión con Meta. Conéctala desde su ficha en /admin.');
        }

        try {
            $this->renovarSiProcede($conexion);

            $hasta = now()->toDateString();
            $desde = now()->subDays($dias - 1)->toDateString();

            $filas = $this->filas($cuenta, $conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('Meta API · %s → %s', $desde, $hasta),
                'user_id' => null,
            ]);

            $conexion->forceFill([
                'ultima_sincronizacion' => now(),
                'ultimo_error'          => null,
            ])->save();

            return $res;
        } catch (Throwable $e) {
            // El error queda registrado en la conexión para verlo en /admin,
            // pero se relanza: quien llama (comando, botón) decide cómo avisar.
            $conexion->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }

    /**
     * Insights de campaña día a día, ya como filas normalizadas para
     * CampaignImporter::importRows(). Sigue la paginación de Graph.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(Cuenta $cuenta, ConexionApi $conexion, string $desde, string $hasta): array
    {
        $url = $this->url('act_' . $this->cuentaExternaId($cuenta) . '/insights', [
            'level'          => 'campaign',
            'fields'         => 'campaign_name,impressions,reach,clicks,spend,actions',
            'time_increment' => 1,
            'time_range'     => json_encode(['since' => $desde, 'until' => $hasta]),
            'limit'          => 500,
        ]);

        $out = [];

        while ($url) {
            $resp = Http::withToken($conexion->access_token)
                ->timeout(60)
                ->retry(2, 500, throw: false)
                ->get($url);

            if ($resp->failed()) {
                throw new RuntimeException('Meta Insights: ' . $this->mensajeError($resp->json(), $resp->body()));
            }

            foreach ($resp->json('data', []) as $fila) {
                $out[] = $this->normalizar($fila);
            }

            $url = $resp->json('paging.next');
        }

        return $out;
    }

    /**
     * Una fila de insights de Graph → fila normalizada del importador.
     * Graph devuelve los números como string; la fecha ya llega Y-m-d
     * (date_start), que es justo lo que exige importRows().
     *
     * @param  array<string,mixed> $fila
     * @return array<string,mixed>
     */
    private function normalizar(array $fila): array
    {
        $nombre = trim((string) ($fila['campaign_name'] ?? ''));

        $leads = 0;
        foreach (($fila['actions'] ?? []) as $accion) {
            if (in_array($accion['action_type'] ?? '', self::TIPOS_LEAD, true)) {
                $leads += (float) ($accion['value'] ?? 0);
            }
        }

        return [
            'match_key'   => Linea::normalizar($nombre),
            'nombre'      => $nombre,
            'fecha'       => (string) ($fila['date_start'] ?? ''),
            'impresiones' => (int) ($fila['impressions'] ?? 0),
            'alcance'     => (int) ($fila['reach'] ?? 0),
            'clics'       => (int) ($fila['clicks'] ?? 0),
            'gasto_real'  => round((float) ($fila['spend'] ?? 0), 2), // en la moneda de la cuenta
            'leads'       => $leads,
        ];
    }

    /** El external_id tal como lo espera Graph: solo dígitos, sin "act_". */
    private function cuentaExternaId(Cuenta $cuenta): string
    {
        $id = preg_replace('/\s+/', '', (string) $cuenta->external_id);

        if ($id === null || $id === '') {
            throw new RuntimeException('Falta el ID de cuenta en la plataforma (external_id) en la ficha de la Cuenta.');
        }

        return str_starts_with($id, 'act_') ? substr($id, 4) : $id;
    }

    /**
     * GET contra Graph con el token dado; lanza con el mensaje de Meta si falla
     * (así el admin ve "token caducado" o "sin permisos", no un error genérico).
     *
     * @return array<string,mixed>
     */
    private function get(string $path, array $params, ?string $token = null): array
    {
        $req = Http::timeout(30)->withToken($token ?? '');

        $resp = $req->get($this->url($path, $params));

        if ($resp->failed()) {
            throw new RuntimeException('Meta: ' . $this->mensajeError($resp->json(), $resp->body()));
        }

        return (array) $resp->json();
    }

    /** URL de un endpoint de Graph con los parámetros como query string. */
    private function url(string $path, array $params): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s?%s',
            config('services.meta.api_version'),
            $path,
            http_build_query($params)
        );
    }

    /** @param  array<string,mixed>|null  $json */
    private function mensajeError(?array $json, string $cuerpo): string
    {
        $error = $json['error'] ?? [];

        return isset($error['message'])
            ? trim((string) $error['message'])
            : mb_substr($cuerpo, 0, 300);
    }
}

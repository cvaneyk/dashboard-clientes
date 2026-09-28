<?php

namespace App\Services\Sync;

use App\Models\ConexionApi;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Services\Import\CampaignImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Conector con la Google Ads API (REST).
 *
 * Mismo contrato que MetaConnector: baja métricas de campaña día a día y las
 * vuelca en CampaignImporter::importRows(), así que API e informe manual son
 * intercambiables e idempotentes entre sí. Solo esta clase sabe de GAQL.
 *
 * DIFERENCIAS CON META, que explican por qué el código no es simétrico:
 *
 *  - Autenticación en dos escalones. El refresh_token no caduca; con él se pide
 *    un access_token que dura una hora. Se guarda el access_token con su
 *    expires_at para no pedir uno en cada llamada.
 *  - Hace falta un developer_token de agencia (aprobado por Google) ADEMÁS del
 *    OAuth del usuario.
 *  - Con MCC, un único refresh token sirve para todos los clientes: lo que
 *    cambia por cliente es el customer_id (el external_id de la Cuenta) y la
 *    cabecera login-customer-id, que dice desde qué MCC se entra.
 *  - No existe "alcance" como métrica: se importa a 0.
 *  - Los importes vienen en micros (1.000.000 = 1 €).
 */
class GoogleAdsConnector implements Conector
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';

    public function __construct(private CampaignImporter $importer)
    {
    }

    /** ¿Están las tres piezas de agencia en el .env? */
    public function appConfigurada(): bool
    {
        return (bool) config('services.google_ads.client_id')
            && (bool) config('services.google_ads.client_secret')
            && (bool) config('services.google_ads.developer_token');
    }

    /**
     * URL del consentimiento de Google. access_type=offline y prompt=consent
     * son imprescindibles: sin ellos Google NO devuelve refresh_token, y sin
     * refresh_token la conexión moriría en una hora.
     */
    public function urlAutorizacion(string $estado, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => config('services.google_ads.client_id'),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $estado,
        ]);
    }

    /**
     * Canjea el `code` del callback por refresh_token + access_token y
     * comprueba que con eso se puede leer de verdad la cuenta del portal.
     */
    public function guardarTokenOauth(Cuenta $cuenta, string $code, string $redirectUri): ConexionApi
    {
        $tokens = $this->postToken([
            'code'          => $code,
            'client_id'     => config('services.google_ads.client_id'),
            'client_secret' => config('services.google_ads.client_secret'),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $refresh = (string) ($tokens['refresh_token'] ?? '');

        if ($refresh === '') {
            throw new RuntimeException(
                'Google no devolvió refresh token. Suele pasar si esa cuenta ya había autorizado antes: '
                . 'quita el acceso en myaccount.google.com/permissions y vuelve a conectar.'
            );
        }

        return $this->guardar($cuenta, $refresh, null, [
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'expires_at'   => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'metodo'       => 'oauth',
        ]);
    }

    /**
     * Alta con un refresh token pegado a mano: el caso de la MCC de OliMedia,
     * donde un solo token sirve para todas las cuentas que cuelgan de ella.
     */
    public function guardarRefreshToken(Cuenta $cuenta, string $refreshToken, ?string $loginCustomerId = null): ConexionApi
    {
        return $this->guardar($cuenta, trim($refreshToken), $loginCustomerId, ['metodo' => 'mcc']);
    }

    /** Ventana móvil de los últimos días (lo que usa la sincronización diaria). */
    public function sincronizar(Cuenta $cuenta, int $dias = 3): array
    {
        $dias = max(1, $dias);

        return $this->sincronizarRango(
            $cuenta,
            now()->subDays($dias - 1)->toDateString(),
            now()->toDateString()
        );
    }

    /**
     * Sincroniza un rango concreto (ambas fechas inclusive). Idempotente:
     * rebajar días ya importados los sobrescribe.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizarRango(Cuenta $cuenta, string $desde, string $hasta): array
    {
        $conexion = $cuenta->conexionApi;

        if (! $conexion) {
            throw new RuntimeException('Esta cuenta no tiene conexión con Google Ads. Conéctala desde su ficha en /admin.');
        }

        [$desde, $hasta] = $this->rango($desde, $hasta);

        try {
            $filas = $this->filas($cuenta, $conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('Google Ads API · %s → %s', $desde, $hasta),
                'user_id' => null,
            ]);

            // Igual que en Meta: el presupuesto es un apoyo, no puede tumbar la
            // sincronización de métricas si falla.
            try {
                $this->refrescarPresupuestos($cuenta, $conexion);
            } catch (Throwable $e) {
                report($e);
            }

            $conexion->forceFill([
                'ultima_sincronizacion' => now(),
                'ultimo_error'          => null,
            ])->save();

            return $res;
        } catch (Throwable $e) {
            $conexion->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }
    }

    /**
     * Métricas por campaña y día, ya normalizadas para importRows().
     *
     * Seleccionar segments.date es lo que hace que Google devuelva una fila por
     * campaña Y día en vez del agregado del periodo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(Cuenta $cuenta, ConexionApi $conexion, string $desde, string $hasta): array
    {
        $gaql = sprintf(
            'SELECT campaign.name, segments.date, metrics.impressions, metrics.clicks, '
            . 'metrics.cost_micros, metrics.conversions FROM campaign '
            . "WHERE segments.date BETWEEN '%s' AND '%s'",
            $desde,
            $hasta
        );

        $out = [];

        foreach ($this->buscar($cuenta, $conexion, $gaql) as $fila) {
            $nombre = trim((string) data_get($fila, 'campaign.name', ''));
            $fecha  = (string) data_get($fila, 'segments.date', '');

            if ($nombre === '' || $fecha === '') {
                continue;
            }

            $out[] = [
                'match_key'   => Linea::normalizar($nombre),
                'nombre'      => $nombre,
                'fecha'       => $fecha, // Google ya lo da en Y-m-d
                'impresiones' => (int) data_get($fila, 'metrics.impressions', 0),
                'alcance'     => 0, // Google Ads no expone alcance en este informe
                'clics'       => (int) data_get($fila, 'metrics.clicks', 0),
                'gasto_real'  => $this->importe(data_get($fila, 'metrics.costMicros')),
                'leads'       => round((float) data_get($fila, 'metrics.conversions', 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Anota en cada Línea el presupuesto diario que tiene su campaña en Google,
     * como referencia interna. NUNCA toca presupuesto_eventos (lo que se
     * factura al cliente lo decide OliMedia).
     *
     * @return int Líneas actualizadas.
     */
    public function refrescarPresupuestos(Cuenta $cuenta, ConexionApi $conexion): int
    {
        $gaql = 'SELECT campaign.name, campaign_budget.amount_micros, campaign_budget.period '
            . "FROM campaign WHERE campaign.status != 'REMOVED'";

        $lineas  = $cuenta->lineas()->get()->keyBy('match_key');
        $tocadas = 0;

        foreach ($this->buscar($cuenta, $conexion, $gaql) as $fila) {
            $nombre  = trim((string) data_get($fila, 'campaign.name', ''));
            $importe = $this->importe(data_get($fila, 'campaignBudget.amountMicros'));
            $linea   = $lineas->get(Linea::normalizar($nombre));

            if (! $linea || $importe <= 0) {
                continue;
            }

            $linea->forceFill([
                'presupuesto_plataforma'       => $importe,
                // En Google el presupuesto de campaña es diario salvo que sea
                // de tipo periodo personalizado; se respeta lo que diga la API.
                'presupuesto_plataforma_tipo'  => data_get($fila, 'campaignBudget.period') === 'CUSTOM_PERIOD'
                    ? 'total'
                    : 'diario',
                'presupuesto_plataforma_nivel' => 'campania',
                'presupuesto_plataforma_actualizado_en' => now(),
            ])->save();

            $tocadas++;
        }

        return $tocadas;
    }

    /**
     * Lanza una consulta GAQL siguiendo la paginación y devuelve las filas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buscar(Cuenta $cuenta, ConexionApi $conexion, string $gaql): array
    {
        $url = sprintf(
            'https://googleads.googleapis.com/%s/customers/%s/googleAds:search',
            config('services.google_ads.api_version'),
            $this->customerId($cuenta)
        );

        $token     = $this->accessToken($conexion);
        $out       = [];
        $pageToken = null;

        do {
            $resp = Http::withToken($token)
                ->withHeaders($this->cabeceras($conexion))
                ->timeout(120)
                ->retry(2, 500, throw: false)
                // Sin pageSize: desde v17 Google lo rechaza y pagina fijo a 10.000 filas.
                ->post($url, array_filter([
                    'query'     => $gaql,
                    'pageToken' => $pageToken,
                ]));

            if ($resp->failed()) {
                throw new RuntimeException('Google Ads: ' . $this->mensajeError($resp->json(), $resp->body()));
            }

            foreach ($resp->json('results', []) as $fila) {
                $out[] = $fila;
            }

            $pageToken = $resp->json('nextPageToken');
        } while ($pageToken);

        return $out;
    }

    /**
     * Access token vigente. Si el guardado ha caducado (duran una hora), se
     * pide otro con el refresh token y se guarda para las siguientes llamadas.
     */
    private function accessToken(ConexionApi $conexion): string
    {
        $vigente = $conexion->access_token
            && $conexion->expires_at
            && $conexion->expires_at->gt(now()->addMinutes(2));

        if ($vigente) {
            return (string) $conexion->access_token;
        }

        if (! $conexion->refresh_token) {
            throw new RuntimeException('La conexión no tiene refresh token de Google. Vuelve a conectarla desde la ficha de la Cuenta.');
        }

        $tokens = $this->postToken([
            'client_id'     => config('services.google_ads.client_id'),
            'client_secret' => config('services.google_ads.client_secret'),
            'refresh_token' => $conexion->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        $token = (string) ($tokens['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Google no devolvió access token al renovar. Revisa que el acceso no se haya revocado.');
        }

        $conexion->forceFill([
            'access_token' => $token,
            'expires_at'   => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();

        return $token;
    }

    /** @return array<string,string> */
    private function cabeceras(ConexionApi $conexion): array
    {
        $cabeceras = ['developer-token' => (string) config('services.google_ads.developer_token')];

        // Solo se manda si se entra a través de una cuenta de administrador.
        $mcc = $this->soloDigitos($conexion->login_customer_id ?: (string) config('services.google_ads.login_customer_id'));

        if ($mcc !== '') {
            $cabeceras['login-customer-id'] = $mcc;
        }

        return $cabeceras;
    }

    /**
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function postToken(array $datos): array
    {
        $resp = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', $datos);

        if ($resp->failed()) {
            $json = $resp->json();
            $msg  = $json['error_description'] ?? $json['error'] ?? mb_substr($resp->body(), 0, 300);

            throw new RuntimeException('Google OAuth: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return (array) $resp->json();
    }

    /** @param  array<string,mixed>  $extras */
    private function guardar(Cuenta $cuenta, string $refresh, ?string $loginCustomerId, array $extras): ConexionApi
    {
        $conexion = ConexionApi::updateOrCreate(
            ['cuenta_id' => $cuenta->id],
            array_merge([
                'refresh_token'     => $refresh,
                'login_customer_id' => $this->soloDigitos((string) $loginCustomerId) ?: null,
                'access_token'      => '',
                'expires_at'        => null,
                'ultimo_error'      => null,
            ], $extras)
        );

        // Una conexión que no lee nada no sirve: se comprueba antes de darla
        // por buena, para que el error salga al conectar y no de madrugada.
        $this->buscar($cuenta, $conexion, 'SELECT customer.id FROM customer LIMIT 1');

        return $conexion;
    }

    /** El customer_id como lo espera la API: solo dígitos, sin guiones. */
    private function customerId(Cuenta $cuenta): string
    {
        $id = $this->soloDigitos((string) $cuenta->external_id);

        if ($id === '') {
            throw new RuntimeException('Falta el ID de cliente de Google Ads (external_id) en la ficha de la Cuenta. Es el número de 10 cifras, con o sin guiones.');
        }

        return $id;
    }

    private function soloDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    /** Los importes de Google vienen en micros: 1.000.000 = 1 €. */
    private function importe(mixed $micros): float
    {
        return round(((float) $micros) / 1000000, 2);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function rango(string $desde, string $hasta): array
    {
        try {
            $ini = CarbonImmutable::parse($desde)->startOfDay();
            $fin = CarbonImmutable::parse($hasta)->startOfDay();
        } catch (Throwable) {
            throw new RuntimeException('Fechas no válidas: se esperan dos fechas en formato AAAA-MM-DD.');
        }

        if ($ini->gt($fin)) {
            [$ini, $fin] = [$fin, $ini];
        }

        $hoy = CarbonImmutable::now()->startOfDay();

        if ($fin->gt($hoy)) {
            $fin = $hoy;
        }

        if ($ini->gt($hoy)) {
            throw new RuntimeException('El rango empieza en el futuro: no hay datos que traer.');
        }

        return [$ini->toDateString(), $fin->toDateString()];
    }

    /**
     * Google anida el motivo real dentro de error.details[].errors[].message;
     * sacarlo es la diferencia entre "400 Bad Request" y "el developer token no
     * tiene acceso a esta cuenta".
     *
     * @param  array<string,mixed>|null  $json
     */
    private function mensajeError(?array $json, string $cuerpo): string
    {
        $detalle = data_get($json, 'error.details.0.errors.0.message');

        if (is_string($detalle) && $detalle !== '') {
            return $detalle;
        }

        $mensaje = data_get($json, 'error.message');

        return is_string($mensaje) && $mensaje !== ''
            ? $mensaje
            : mb_substr($cuerpo, 0, 300);
    }
}

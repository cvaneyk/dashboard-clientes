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
 * Conector con la Ads API de Spotify (v3).
 *
 * Mismo contrato que los demás: baja métricas de campaña día a día y las vuelca
 * en CampaignImporter::importRows().
 *
 * LO QUE HACE A SPOTIFY DISTINTO:
 *
 *  - Tokens en dos escalones, como Google: el refresh token no caduca y con él
 *    se pide un access token que dura una hora. No hay token de agencia: quien
 *    autoriza es un usuario de Spotify con acceso a la cuenta en Ads Manager.
 *  - Antes de nada, el client ID de la app tiene que haber aceptado los
 *    términos de la Ads API (adsmanager.spotify.com/api-terms). Sin eso todas
 *    las llamadas fallan aunque el OAuth haya ido bien.
 *  - El informe agregado devuelve las métricas como lista de pares
 *    {field_type, field_value}, no como columnas.
 *  - Con granularidad DAY el rango no puede pasar de 90 días: se trocea.
 *  - Los días son días UTC (la API no deja elegir zona horaria).
 *  - El gasto viene como decimal en la moneda de la cuenta; los presupuestos,
 *    en micros (1.000.000 = 1 €).
 *  - Privacidad: una conversión entre 1 y 4 llega como -5. No se puede saber
 *    el número real, así que se cuenta como 0 (mejor quedarse corto que
 *    facturar leads inventados).
 *  - El presupuesto vive en los ad sets, nunca en la campaña.
 */
class SpotifyConnector implements Conector
{
    private const API = 'https://api-partner.spotify.com/ads/v3';

    /** Máximo de días por petición con granularidad DAY (límite de Spotify). */
    private const DIAS_POR_TRAMO = 90;

    /** Campos de conversión que acepta el informe y pueden contar como lead. */
    private const CONVERSIONES = [
        'LEADS', 'SIGN_UPS', 'PURCHASES', 'ADD_TO_CART', 'START_CHECKOUT', 'PAGE_VIEWS',
        'CUSTOM_EVENT_1', 'CUSTOM_EVENT_2', 'CUSTOM_EVENT_3', 'CUSTOM_EVENT_4', 'CUSTOM_EVENT_5',
    ];

    public function __construct(private CampaignImporter $importer)
    {
    }

    public function appConfigurada(): bool
    {
        return (bool) config('services.spotify_ads.client_id')
            && (bool) config('services.spotify_ads.client_secret');
    }

    /**
     * Campos de conversión que cuentan como lead en esta cuenta, por orden de
     * prioridad. Se usa el primero con valor, nunca la suma. Los que Spotify
     * no reconoce se ignoran: pedir un campo inválido tumba el informe entero.
     *
     * @return array<int,string>
     */
    public function tiposConversion(Cuenta $cuenta): array
    {
        $propios = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $cuenta->tipos_conversion)
        )));

        $tipos = $propios !== [] ? $propios : (array) config('services.spotify_ads.tipos_conversion');

        return array_values(array_intersect(
            array_map('strtoupper', $tipos),
            self::CONVERSIONES
        ));
    }

    public function urlAutorizacion(string $estado, string $redirectUri): string
    {
        return 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id'     => config('services.spotify_ads.client_id'),
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'state'         => $estado,
        ]);
    }

    /**
     * Canjea el código por tokens y comprueba que con ellos se lee la cuenta,
     * para que el error salga al conectar y no en la sincronización de noche.
     */
    public function guardarTokenOauth(Cuenta $cuenta, string $code, string $redirectUri): ConexionApi
    {
        $tokens = $this->postToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ]);

        $access  = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');

        if ($access === '' || $refresh === '') {
            throw new RuntimeException('Spotify no devolvió access y refresh token al canjear el código.');
        }

        $conexion = ConexionApi::updateOrCreate(
            ['cuenta_id' => $cuenta->id],
            [
                'metodo'        => 'oauth',
                'access_token'  => $access,
                'refresh_token' => $refresh,
                'expires_at'    => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'ultimo_error'  => null,
            ]
        );

        $this->get('/ad_accounts/' . $this->cuentaExternaId($cuenta), [], $conexion);

        return $conexion;
    }

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
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizarRango(Cuenta $cuenta, string $desde, string $hasta): array
    {
        $conexion = $cuenta->conexionApi;

        if (! $conexion) {
            throw new RuntimeException('Esta cuenta no tiene conexión con Spotify. Conéctala desde su ficha en /admin.');
        }

        [$desde, $hasta] = $this->rango($desde, $hasta);

        try {
            $filas = $this->filas($cuenta, $conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('Spotify Ads API · %s → %s', $desde, $hasta),
                'user_id' => null,
            ]);

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
     * Métricas por campaña y día, ya normalizadas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(Cuenta $cuenta, ConexionApi $conexion, string $desde, string $hasta): array
    {
        $tipos = $this->tiposConversion($cuenta);
        $out   = [];

        foreach ($this->tramos($desde, $hasta) as [$ini, $fin]) {
            foreach ($this->informe($cuenta, $conexion, $ini, $fin, $tipos) as $fila) {
                $stats  = $this->stats($fila);
                $nombre = trim((string) ($fila['entity_name'] ?? ''));

                $out[] = [
                    'match_key'   => $nombre !== '' ? Linea::normalizar($nombre) : '',
                    'nombre'      => $nombre,
                    'fecha'       => substr((string) ($fila['start_time'] ?? ''), 0, 10),
                    'impresiones' => (int) ($stats['IMPRESSIONS'] ?? 0),
                    'alcance'     => (int) ($stats['REACH'] ?? 0),
                    'clics'       => (int) ($stats['CLICKS'] ?? 0),
                    'gasto_real'  => round((float) ($stats['SPEND'] ?? 0), 2),
                    'leads'       => $this->leads($stats, $tipos),
                ];
            }
        }

        return $out;
    }

    /**
     * Anota en cada Línea el presupuesto de su campaña, sumando el de sus ad
     * sets (en Spotify la campaña no tiene presupuesto propio). Referencia
     * interna: NUNCA toca presupuesto_eventos.
     */
    public function refrescarPresupuestos(Cuenta $cuenta, ConexionApi $conexion): int
    {
        $lineas = $cuenta->lineas()->get()->keyBy('match_key');

        if ($lineas->isEmpty()) {
            return 0;
        }

        $cuentaId = $this->cuentaExternaId($cuenta);

        $nombres = [];
        foreach ($this->paginarOffset("/ad_accounts/{$cuentaId}/campaigns", 'campaigns', $conexion) as $c) {
            $nombres[(string) ($c['id'] ?? '')] = (string) ($c['name'] ?? '');
        }

        $presupuestos = []; // campaign_id => [importe, tipo]
        foreach ($this->paginarOffset("/ad_accounts/{$cuentaId}/ad_sets", 'ad_sets', $conexion) as $a) {
            $campana = (string) ($a['campaign_id'] ?? '');
            $micros  = $a['budget']['micro_amount'] ?? null;

            if ($campana === '' || ! is_numeric($micros) || (int) $micros <= 0) {
                continue;
            }

            $tipo = ($a['budget']['type'] ?? '') === 'DAILY' ? 'diario' : 'total';

            $presupuestos[$campana] = [
                'importe' => round(($presupuestos[$campana]['importe'] ?? 0) + (int) $micros / 1_000_000, 2),
                // Si una campaña mezcla ad sets diarios y de periodo, la suma no
                // significa nada exacto: se marca con el primero, como en Meta.
                'tipo'    => $presupuestos[$campana]['tipo'] ?? $tipo,
            ];
        }

        $tocadas = 0;

        foreach ($presupuestos as $campana => $p) {
            $linea = $lineas->get(Linea::normalizar($nombres[$campana] ?? ''));

            if (! $linea) {
                continue;
            }

            $linea->forceFill([
                'presupuesto_plataforma'                => $p['importe'],
                'presupuesto_plataforma_tipo'           => $p['tipo'],
                'presupuesto_plataforma_nivel'          => 'adsets',
                'presupuesto_plataforma_actualizado_en' => now(),
            ])->save();

            $tocadas++;
        }

        return $tocadas;
    }

    /**
     * Informe agregado diario por campaña de un tramo de ≤90 días, con todas
     * sus páginas. La siguiente página se pide SOLO con el continuation_token:
     * Spotify ignora el resto de parámetros y avisa si se mandan.
     *
     * @param  array<int,string>  $tipos
     * @return array<int,array<string,mixed>>
     */
    private function informe(Cuenta $cuenta, ConexionApi $conexion, string $desde, string $hasta, array $tipos): array
    {
        $ruta = '/ad_accounts/' . $this->cuentaExternaId($cuenta) . '/aggregate_reports';

        $params = [
            'entity_type'  => 'CAMPAIGN',
            'granularity'  => 'DAY',
            // DAY exige medianoche UTC; el final es inclusivo (trae el día entero).
            'report_start' => $desde . 'T00:00:00Z',
            'report_end'   => $hasta . 'T00:00:00Z',
            'fields'       => array_values(array_unique(array_merge(
                ['IMPRESSIONS', 'REACH', 'CLICKS', 'SPEND'],
                $tipos
            ))),
            'limit'        => 50,
        ];

        $filas = [];

        do {
            $json   = $this->get($ruta, $params, $conexion);
            $filas  = array_merge($filas, (array) ($json['rows'] ?? []));
            $token  = (string) ($json['continuation_token'] ?? '');
            $params = ['continuation_token' => $token];
        } while ($token !== '');

        return $filas;
    }

    /**
     * Listados paginados por offset (campañas, ad sets).
     *
     * @return array<int,array<string,mixed>>
     */
    private function paginarOffset(string $ruta, string $clave, ConexionApi $conexion): array
    {
        $todos  = [];
        $offset = 0;

        do {
            $json   = $this->get($ruta, ['limit' => 50, 'offset' => $offset], $conexion);
            $pagina = (array) ($json[$clave] ?? []);
            $todos  = array_merge($todos, $pagina);
            $offset += count($pagina);
            $total  = (int) ($json['paging']['total_results'] ?? 0);
        } while ($pagina !== [] && $offset < $total);

        return $todos;
    }

    /**
     * Las métricas llegan como lista de {field_type, field_value}.
     *
     * @param  array<string,mixed>  $fila
     * @return array<string,float>
     */
    private function stats(array $fila): array
    {
        $out = [];

        foreach ((array) ($fila['stats'] ?? []) as $s) {
            $campo = (string) ($s['field_type'] ?? '');

            if ($campo !== '' && is_numeric($s['field_value'] ?? null)) {
                $out[$campo] = (float) $s['field_value'];
            }
        }

        return $out;
    }

    /**
     * Leads de una fila: el primer campo de la lista de prioridad con valor.
     * Los -5 de privacidad (entre 1 y 4 conversiones) no cuentan.
     *
     * @param  array<string,float>  $stats
     * @param  array<int,string>  $tipos
     */
    private function leads(array $stats, array $tipos): float
    {
        foreach ($tipos as $tipo) {
            if (($stats[$tipo] ?? 0) > 0) {
                return round($stats[$tipo], 2);
            }
        }

        return 0.0;
    }

    /**
     * Trocea el rango en bloques de como mucho 90 días (límite de DAY).
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function tramos(string $desde, string $hasta): array
    {
        $ini = CarbonImmutable::parse($desde);
        $fin = CarbonImmutable::parse($hasta);
        $out = [];

        while ($ini->lte($fin)) {
            $corte = $ini->addDays(self::DIAS_POR_TRAMO - 1);
            $corte = $corte->gt($fin) ? $fin : $corte;

            $out[] = [$ini->toDateString(), $corte->toDateString()];
            $ini   = $corte->addDay();
        }

        return $out;
    }

    /**
     * GET a la API con el access token vigente. Los parámetros repetidos
     * (fields=A&fields=B) se montan a mano: Laravel los mandaría como
     * fields[0]=A, que Spotify no entiende.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function get(string $ruta, array $params, ConexionApi $conexion): array
    {
        $query = [];
        foreach ($params as $clave => $valor) {
            foreach ((array) $valor as $v) {
                $query[] = rawurlencode($clave) . '=' . rawurlencode((string) $v);
            }
        }

        $url = self::API . $ruta . ($query ? '?' . implode('&', $query) : '');

        $resp = Http::withToken($this->accessToken($conexion))
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 500, throw: false)
            ->get($url);

        if ($resp->failed()) {
            throw new RuntimeException('Spotify Ads: ' . $this->mensajeError($resp->status(), $resp->json(), $resp->body()));
        }

        return (array) $resp->json();
    }

    /**
     * Access token vigente. Duran una hora: si ha caducado se pide otro con el
     * refresh token (que no caduca) y se guarda para las siguientes llamadas.
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
            throw new RuntimeException('La conexión no tiene refresh token de Spotify. Vuelve a conectarla desde la ficha de la Cuenta.');
        }

        $tokens = $this->postToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $conexion->refresh_token,
        ]);

        $token = (string) ($tokens['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Spotify no devolvió access token al renovar. Vuelve a conectar la cuenta desde su ficha.');
        }

        $conexion->forceFill([
            'access_token'  => $token,
            // Spotify puede rotar el refresh token; si no manda uno, sigue el viejo.
            'refresh_token' => (string) ($tokens['refresh_token'] ?? '') ?: $conexion->refresh_token,
            'expires_at'    => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();

        return $token;
    }

    /**
     * Endpoint de tokens de Spotify: credenciales de la app por Basic auth.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function postToken(array $datos): array
    {
        $resp = Http::asForm()
            ->withBasicAuth(
                (string) config('services.spotify_ads.client_id'),
                (string) config('services.spotify_ads.client_secret')
            )
            ->timeout(30)
            ->post('https://accounts.spotify.com/api/token', $datos);

        if ($resp->failed()) {
            $json = $resp->json();
            $msg  = $json['error_description'] ?? $json['error'] ?? mb_substr($resp->body(), 0, 300);

            throw new RuntimeException('Spotify OAuth: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return (array) $resp->json();
    }

    /** El ID de cuenta publicitaria de Spotify es un UUID. */
    private function cuentaExternaId(Cuenta $cuenta): string
    {
        $id = strtolower(trim((string) $cuenta->external_id));

        if ($id === '') {
            throw new RuntimeException('Falta el ID de cuenta publicitaria de Spotify (external_id) en la ficha de la Cuenta.');
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw new RuntimeException('El ID de cuenta de Spotify (external_id) debe ser un UUID, como aparece en la URL de Ads Manager.');
        }

        return $id;
    }

    /** @return array{0:string,1:string} */
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

    /** @param  array<string,mixed>|null  $json */
    private function mensajeError(int $estado, ?array $json, string $cuerpo): string
    {
        $mensaje = data_get($json, 'error.message') ?? $json['message'] ?? $json['error_description'] ?? null;

        $texto = is_string($mensaje) && $mensaje !== '' ? $mensaje : mb_substr($cuerpo, 0, 300);

        // El fallo típico de una app recién creada: el OAuth funciona pero la
        // API rechaza todo porque el client ID no ha aceptado los términos.
        if (in_array($estado, [401, 403], true)) {
            $texto .= ' · Comprueba que el client ID de la app aceptó los términos de la Ads API '
                . '(adsmanager.spotify.com/api-terms; tarda hasta una hora) y que el usuario que '
                . 'conectó tiene acceso a esta cuenta en Ads Manager.';
        }

        return $texto;
    }
}

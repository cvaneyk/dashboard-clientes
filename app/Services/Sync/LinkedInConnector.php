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
 * Conector con la Marketing API de LinkedIn Ads.
 *
 * Mismo contrato que los demás: baja métricas de campaña día a día y las vuelca
 * en CampaignImporter::importRows().
 *
 * LO QUE HACE A LINKEDIN DISTINTO (y por qué este conector tiene dos llamadas):
 *
 *  - adAnalytics NO devuelve el nombre de la campaña, solo su URN. Hay que
 *    resolver los nombres con una segunda llamada a adCampaigns y cruzarlos.
 *    Una fila cuyo nombre no se pueda resolver se deja sin match_key para que
 *    el importador la cuente como descartada, en vez de crear una línea
 *    llamada "urn:li:sponsoredCampaign:123".
 *  - La API va versionada por cabecera (LinkedIn-Version, formato AAAAMM) y
 *    usa el protocolo Rest.li 2.0, con una sintaxis de parámetros propia
 *    —dateRange=(start:(year:…))— que no es un query string normal.
 *  - El coste viene como decimal en la moneda de la cuenta (no micros ni
 *    céntimos, al revés que Google y Meta).
 *  - Las conversiones se reparten en dos campos según cómo mida el cliente:
 *    oneClickLeads (formularios de LinkedIn) y externalWebsiteConversions
 *    (Insight Tag en la web). Se resuelven por prioridad, igual que en Meta.
 *  - No existe token de agencia: el acceso se concede por LinkedIn Business
 *    Manager y el token sigue siendo del usuario que autoriza.
 */
class LinkedInConnector implements Conector
{
    /** Permisos a pedir; deben coincidir con los concedidos a la app. */
    private function scopes(): string
    {
        return (string) config('services.linkedin_ads.scopes', 'r_ads r_ads_reporting');
    }

    public function __construct(private CampaignImporter $importer)
    {
    }

    public function appConfigurada(): bool
    {
        return (bool) config('services.linkedin_ads.client_id')
            && (bool) config('services.linkedin_ads.client_secret');
    }

    /**
     * Campos de conversión que cuentan como lead en esta cuenta, por orden de
     * prioridad. Se usa el primero con valor, nunca la suma: un lead de
     * formulario suele registrarse también como conversión de web.
     *
     * @return array<int,string>
     */
    public function tiposConversion(Cuenta $cuenta): array
    {
        $propios = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $cuenta->tipos_conversion)
        )));

        return $propios !== [] ? $propios : (array) config('services.linkedin_ads.tipos_conversion');
    }

    public function urlAutorizacion(string $estado, string $redirectUri): string
    {
        // RFC3986 a propósito: los scopes van separados por espacios y
        // http_build_query los codificaría por defecto como '+', que LinkedIn
        // rechaza. Tiene que ser '%20'.
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query(
            [
                'response_type' => 'code',
                'client_id'     => config('services.linkedin_ads.client_id'),
                'redirect_uri'  => $redirectUri,
                'state'         => $estado,
                'scope'         => $this->scopes(),
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Canjea el código por tokens. LinkedIn da un access token de ~60 días y,
     * en las apps aprobadas para Marketing, un refresh token de ~1 año.
     */
    public function guardarTokenOauth(Cuenta $cuenta, string $code, string $redirectUri): ConexionApi
    {
        $tokens = $this->postToken([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => config('services.linkedin_ads.client_id'),
            'client_secret' => config('services.linkedin_ads.client_secret'),
            'redirect_uri'  => $redirectUri,
        ]);

        $access = (string) ($tokens['access_token'] ?? '');

        if ($access === '') {
            throw new RuntimeException('LinkedIn no devolvió access token al canjear el código.');
        }

        $conexion = ConexionApi::updateOrCreate(
            ['cuenta_id' => $cuenta->id],
            [
                'metodo'        => 'oauth',
                'access_token'  => $access,
                'refresh_token' => (string) ($tokens['refresh_token'] ?? '') ?: null,
                'expires_at'    => now()->addSeconds((int) ($tokens['expires_in'] ?? 0)) ,
                'ultimo_error'  => null,
            ]
        );

        // Una conexión que no lee nada no sirve: se comprueba al conectar para
        // que el error salga aquí y no en la sincronización de madrugada.
        $this->campanas($cuenta, $conexion, []);

        return $conexion;
    }

    /**
     * LinkedIn no renueva el access token al usarlo: cuando caduca hay que
     * canjear el refresh token. Si la app no tiene refresh token (no aprobada
     * para Marketing), toca volver a autorizar a mano cada 60 días.
     */
    public function renovarSiProcede(ConexionApi $conexion): void
    {
        $vigente = $conexion->expires_at && $conexion->expires_at->gt(now()->addDays(2));

        if ($vigente || ! $conexion->refresh_token) {
            return;
        }

        $tokens = $this->postToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $conexion->refresh_token,
            'client_id'     => config('services.linkedin_ads.client_id'),
            'client_secret' => config('services.linkedin_ads.client_secret'),
        ]);

        $access = (string) ($tokens['access_token'] ?? '');

        if ($access === '') {
            throw new RuntimeException('LinkedIn no devolvió access token al renovar. Vuelve a conectar la cuenta desde su ficha.');
        }

        $conexion->forceFill([
            'access_token'  => $access,
            'refresh_token' => (string) ($tokens['refresh_token'] ?? '') ?: $conexion->refresh_token,
            'expires_at'    => now()->addSeconds((int) ($tokens['expires_in'] ?? 0)),
        ])->save();
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
            throw new RuntimeException('Esta cuenta no tiene conexión con LinkedIn. Conéctala desde su ficha en /admin.');
        }

        [$desde, $hasta] = $this->rango($desde, $hasta);

        try {
            $this->renovarSiProcede($conexion);

            $filas = $this->filas($cuenta, $conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('LinkedIn Ads API · %s → %s', $desde, $hasta),
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
        $elementos = $this->analytics($cuenta, $conexion, $desde, $hasta);

        // adAnalytics solo da URNs: los nombres van en otra llamada.
        $ids = [];
        foreach ($elementos as $e) {
            if ($id = $this->idCampana($e)) {
                $ids[$id] = true;
            }
        }

        $nombres = $this->campanas($cuenta, $conexion, array_keys($ids));
        $tipos   = $this->tiposConversion($cuenta);
        $out     = [];

        foreach ($elementos as $e) {
            $fecha = $this->fecha($e['dateRange']['start'] ?? null);

            if (! $fecha) {
                continue;
            }

            $id     = $this->idCampana($e);
            $nombre = $id ? ($nombres[$id]['nombre'] ?? null) : null;

            $out[] = [
                // Sin nombre no se puede emparejar: se deja la clave vacía y el
                // importador la cuenta como descartada, que es visible en el log.
                'match_key'   => $nombre ? Linea::normalizar($nombre) : '',
                'nombre'      => $nombre ?? '',
                'fecha'       => $fecha,
                'impresiones' => (int) ($e['impressions'] ?? 0),
                'alcance'     => 0, // LinkedIn no da alcance en este informe
                'clics'       => (int) ($e['clicks'] ?? 0),
                'gasto_real'  => round((float) ($e['costInLocalCurrency'] ?? 0), 2),
                'leads'       => $this->leads($e, $tipos),
            ];
        }

        return $out;
    }

    /**
     * Anota el presupuesto de cada campaña como referencia interna.
     * NUNCA toca presupuesto_eventos.
     */
    public function refrescarPresupuestos(Cuenta $cuenta, ConexionApi $conexion): int
    {
        $lineas = $cuenta->lineas()->get()->keyBy('match_key');

        if ($lineas->isEmpty()) {
            return 0;
        }

        $tocadas = 0;

        foreach ($this->campanas($cuenta, $conexion, []) as $campana) {
            $linea = $lineas->get(Linea::normalizar($campana['nombre']));

            if (! $linea || $campana['importe'] === null) {
                continue;
            }

            $linea->forceFill([
                'presupuesto_plataforma'                => $campana['importe'],
                'presupuesto_plataforma_tipo'           => $campana['tipo'],
                'presupuesto_plataforma_nivel'          => 'campania',
                'presupuesto_plataforma_actualizado_en' => now(),
            ])->save();

            $tocadas++;
        }

        return $tocadas;
    }

    /**
     * Informe diario por campaña. La sintaxis Rest.li de dateRange y accounts
     * no es un query string normal, así que la URL se monta a mano.
     *
     * @return array<int,array<string,mixed>>
     */
    private function analytics(Cuenta $cuenta, ConexionApi $conexion, string $desde, string $hasta): array
    {
        $ini = CarbonImmutable::parse($desde);
        $fin = CarbonImmutable::parse($hasta);

        $url = 'https://api.linkedin.com/rest/adAnalytics?' . implode('&', [
            'q=analytics',
            'pivot=CAMPAIGN',
            'timeGranularity=DAILY',
            sprintf(
                'dateRange=(start:(year:%d,month:%d,day:%d),end:(year:%d,month:%d,day:%d))',
                $ini->year, $ini->month, $ini->day,
                $fin->year, $fin->month, $fin->day
            ),
            'accounts=List(' . rawurlencode('urn:li:sponsoredAccount:' . $this->cuentaExternaId($cuenta)) . ')',
            'fields=' . implode(',', [
                'dateRange', 'pivotValues', 'impressions', 'clicks',
                'costInLocalCurrency', 'externalWebsiteConversions', 'oneClickLeads',
            ]),
        ]);

        return (array) ($this->get($url, $conexion)['elements'] ?? []);
    }

    /**
     * Nombres y presupuestos de las campañas. Con $ids resuelve solo esas; sin
     * ellos, trae las de la cuenta (que es lo que necesita el presupuesto).
     *
     * @param  array<int,string>  $ids
     * @return array<string,array{nombre:string,importe:?float,tipo:?string}>
     */
    private function campanas(Cuenta $cuenta, ConexionApi $conexion, array $ids): array
    {
        $cuentaUrn = 'urn:li:sponsoredAccount:' . $this->cuentaExternaId($cuenta);

        $url = $ids === []
            ? 'https://api.linkedin.com/rest/adCampaigns?q=search&search=(account:(values:List('
                . rawurlencode($cuentaUrn) . ')))'
            : 'https://api.linkedin.com/rest/adCampaigns?ids=List(' . implode(',', $ids) . ')';

        $resp = $this->get($url, $conexion);

        // La búsqueda devuelve 'elements'; la consulta por ids, 'results'.
        $campanas = $resp['elements'] ?? array_values((array) ($resp['results'] ?? []));

        $out = [];

        foreach ((array) $campanas as $c) {
            $id = (string) ($c['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $diario = $this->importe($c['dailyBudget'] ?? null);
            $total  = $this->importe($c['totalBudget'] ?? null);

            $out[$id] = [
                'nombre'  => trim((string) ($c['name'] ?? '')),
                'importe' => $diario ?? $total,
                'tipo'    => $diario !== null ? 'diario' : ($total !== null ? 'total' : null),
            ];
        }

        return $out;
    }

    /**
     * Leads de una fila: el primer campo de la lista de prioridad con valor.
     *
     * @param  array<string,mixed>  $elemento
     * @param  array<int,string>  $tipos
     */
    private function leads(array $elemento, array $tipos): float
    {
        foreach ($tipos as $tipo) {
            if (isset($elemento[$tipo]) && (float) $elemento[$tipo] > 0) {
                return round((float) $elemento[$tipo], 2);
            }
        }

        return 0.0;
    }

    /** El id numérico de la campaña, sacado del URN de pivotValues. */
    private function idCampana(array $elemento): ?string
    {
        foreach ((array) ($elemento['pivotValues'] ?? []) as $urn) {
            if (preg_match('/sponsoredCampaign:(\d+)/', (string) $urn, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @param  array<string,mixed>|null  $inicio */
    private function fecha(?array $inicio): ?string
    {
        if (! isset($inicio['year'], $inicio['month'], $inicio['day'])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $inicio['year'], $inicio['month'], $inicio['day']);
    }

    /**
     * Los importes de LinkedIn son objetos {currencyCode, amount} con el
     * importe ya en la moneda, como cadena decimal.
     */
    private function importe(mixed $dinero): ?float
    {
        $cantidad = is_array($dinero) ? ($dinero['amount'] ?? null) : null;

        if ($cantidad === null || ! is_numeric($cantidad)) {
            return null;
        }

        $importe = round((float) $cantidad, 2);

        return $importe > 0 ? $importe : null;
    }

    /** @return array<string,mixed> */
    private function get(string $url, ConexionApi $conexion): array
    {
        $resp = Http::withToken($conexion->access_token)
            ->withHeaders([
                'LinkedIn-Version'          => (string) config('services.linkedin_ads.api_version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->timeout(120)
            ->retry(2, 500, throw: false)
            ->get($url);

        if ($resp->failed()) {
            throw new RuntimeException('LinkedIn Ads: ' . $this->mensajeError($resp->json(), $resp->body()));
        }

        return (array) $resp->json();
    }

    /**
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function postToken(array $datos): array
    {
        $resp = Http::asForm()->timeout(30)->post('https://www.linkedin.com/oauth/v2/accessToken', $datos);

        if ($resp->failed()) {
            $json = $resp->json();
            $msg  = $json['error_description'] ?? $json['error'] ?? mb_substr($resp->body(), 0, 300);

            throw new RuntimeException('LinkedIn OAuth: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return (array) $resp->json();
    }

    private function cuentaExternaId(Cuenta $cuenta): string
    {
        $id = preg_replace('/\D+/', '', (string) $cuenta->external_id) ?? '';

        if ($id === '') {
            throw new RuntimeException('Falta el ID de cuenta publicitaria de LinkedIn (external_id) en la ficha de la Cuenta.');
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
    private function mensajeError(?array $json, string $cuerpo): string
    {
        $mensaje = $json['message'] ?? data_get($json, 'error.message');

        return is_string($mensaje) && $mensaje !== ''
            ? $mensaje
            : mb_substr($cuerpo, 0, 300);
    }
}

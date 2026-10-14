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
 * Conector con la Marketing API de Meta (Graph API).
 *
 * Baja los insights de campaña día a día y los vuelca como filas normalizadas
 * en CampaignImporter::importRows(), que es el mismo destino que la subida
 * manual de informes. Por eso sincronizar por API y subir el Excel a mano son
 * intercambiables e idempotentes entre sí.
 *
 * Dos métodos de autenticación, elegidos por cuenta desde /admin:
 *   - 'system_user' → token de System User del Business Manager de la agencia.
 *     No caduca; vale para las cuentas que la agencia gestiona.
 *   - 'oauth' → el cliente conecta su propia cuenta con Facebook Login.
 *     Da un token de vida larga (~60 días) que se renueva aquí solo cuando
 *     toca, sin que nadie tenga que volver a hacer login.
 *
 * La app de Facebook (META_APP_ID / META_APP_SECRET) solo es necesaria para
 * el método OAuth.
 */
class MetaConnector implements Conector
{
    private const SCOPE = 'ads_read';

    /**
     * Tipos de acción que cuentan como lead en esta cuenta, por orden de
     * prioridad. Se coge el PRIMERO que aparezca en la fila, nunca la suma:
     * Meta devuelve a la vez el agregado ('complete_registration') y el
     * específico del píxel ('offsite_conversion.fb_pixel_...'), que son el
     * mismo evento contado dos veces.
     *
     * @return array<int,string>
     */
    public function tiposConversion(Cuenta $cuenta): array
    {
        $propios = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $cuenta->tipos_conversion)
        )));

        return $propios !== [] ? $propios : (array) config('services.meta.tipos_conversion');
    }

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
        $dias = max(1, $dias);

        return $this->sincronizarRango(
            $cuenta,
            now()->subDays($dias - 1)->toDateString(),
            now()->toDateString()
        );
    }

    /**
     * Sincroniza un rango concreto de fechas (ambas inclusive), para recuperar
     * histórico o rehacer un periodo suelto. Es idempotente igual que el resto:
     * volver a bajar días ya importados los sobrescribe, no los duplica.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizarRango(Cuenta $cuenta, string $desde, string $hasta): array
    {
        $conexion = $cuenta->conexionApi;

        if (! $conexion) {
            throw new RuntimeException('Esta cuenta no tiene conexión con Meta. Conéctala desde su ficha en /admin.');
        }

        [$desde, $hasta] = $this->rango($desde, $hasta);

        try {
            $this->renovarSiProcede($conexion);

            $filas = $this->filas($cuenta, $conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('Meta API · %s → %s', $desde, $hasta),
                'user_id' => null,
            ]);

            // El presupuesto de plataforma es un extra de referencia para el
            // panel: si Meta falla aquí, la sincronización de métricas ya está
            // hecha y no se tira por esto.
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
            // El error queda registrado en la conexión para verlo en /admin,
            // pero se relanza: quien llama (comando, botón) decide cómo avisar.
            $conexion->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }

    /**
     * Guarda en cada Línea el presupuesto que tiene su campaña EN META, como
     * dato interno de referencia para el account manager.
     *
     * NO es el presupuesto del portal: el que se factura al cliente sigue
     * siendo presupuesto_eventos.objetivo_mes, que se decide a mano y lleva
     * margen. Esta cifra es de la familia de gasto_real (uso interno) y no
     * entra en el motor de precios ni se enseña al cliente.
     *
     * En Meta el presupuesto puede estar en dos sitios:
     *   - CBO: en la propia campaña.
     *   - Sin CBO: en cada adset; entonces se suman los de la campaña.
     * Y puede ser diario ('diario') o de todo el periodo ('total'), que no son
     * comparables entre sí: por eso se guarda también de qué tipo es.
     *
     * @return int Líneas actualizadas.
     */
    public function refrescarPresupuestos(Cuenta $cuenta, ConexionApi $conexion): int
    {
        $acto = 'act_' . $this->cuentaExternaId($cuenta);

        $campañas = $this->paginar(
            $this->url($acto . '/campaigns', [
                'fields' => 'id,name,daily_budget,lifetime_budget',
                'limit'  => 500,
            ]),
            $conexion->access_token
        );

        // Presupuesto propio de la campaña (CBO).
        $presupuestos = [];   // campaign_id => [importe, tipo, nivel, nombre]
        foreach ($campañas as $c) {
            $id     = (string) ($c['id'] ?? '');
            $diario = $this->importe($c['daily_budget'] ?? null);
            $total  = $this->importe($c['lifetime_budget'] ?? null);

            $presupuestos[$id] = [
                'nombre'  => (string) ($c['name'] ?? ''),
                'importe' => $diario ?? $total,
                'tipo'    => $diario !== null ? 'diario' : ($total !== null ? 'total' : null),
                'nivel'   => 'campania',
            ];
        }

        // Para las campañas sin presupuesto propio, se suma el de sus adsets.
        $faltan = array_filter($presupuestos, fn ($p) => $p['importe'] === null);

        if ($faltan !== []) {
            $adsets = $this->paginar(
                $this->url($acto . '/adsets', [
                    'fields' => 'campaign_id,daily_budget,lifetime_budget',
                    'limit'  => 500,
                ]),
                $conexion->access_token
            );

            foreach ($adsets as $a) {
                $campaña = (string) ($a['campaign_id'] ?? '');

                if (! isset($faltan[$campaña])) {
                    continue;
                }

                $diario = $this->importe($a['daily_budget'] ?? null);
                $total  = $this->importe($a['lifetime_budget'] ?? null);
                $tipo   = $diario !== null ? 'diario' : ($total !== null ? 'total' : null);

                if ($tipo === null) {
                    continue;
                }

                $acumulado = $presupuestos[$campaña];

                $presupuestos[$campaña] = [
                    'nombre'  => $acumulado['nombre'],
                    'importe' => round((float) $acumulado['importe'] + ($diario ?? $total), 2),
                    // Si una campaña mezcla adsets diarios y de periodo, la suma
                    // no significaría nada: se marca con el primero y el panel
                    // avisa de que es una suma de adsets.
                    'tipo'    => $acumulado['tipo'] ?? $tipo,
                    'nivel'   => 'adsets',
                ];
            }
        }

        // Las líneas se emparejan por la misma clave normalizada que las métricas.
        $lineas = $cuenta->lineas()->get()->keyBy('match_key');
        $tocadas = 0;

        foreach ($presupuestos as $p) {
            $linea = $lineas->get(Linea::normalizar($p['nombre']));

            if (! $linea || $p['importe'] === null) {
                continue;
            }

            $linea->forceFill([
                'presupuesto_plataforma'                => $p['importe'],
                'presupuesto_plataforma_tipo'           => $p['tipo'],
                'presupuesto_plataforma_nivel'          => $p['nivel'],
                'presupuesto_plataforma_actualizado_en' => now(),
            ])->save();

            $tocadas++;
        }

        return $tocadas;
    }

    /**
     * Valida el rango pedido y lo deja en Y-m-d ordenado. Meta no acepta fechas
     * futuras en los insights, así que el final se recorta a hoy.
     *
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
     * Graph devuelve los importes en la unidad menor de la moneda de la cuenta
     * (céntimos en euros): "1500" son 15,00 €. Se asume una moneda con dos
     * decimales, que es el caso de todas las cuentas del portal.
     */
    private function importe(mixed $valor): ?float
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        $importe = round(((float) $valor) / 100, 2);

        return $importe > 0 ? $importe : null;
    }

    /**
     * Recorre un listado de Graph siguiendo paginación y devuelve todos los
     * elementos de 'data'.
     *
     * @return array<int,array<string,mixed>>
     */
    private function paginar(string $url, string $token): array
    {
        $out = [];

        while ($url) {
            $resp = Http::withToken($token)->timeout(60)->retry(2, 500, throw: false)->get($url);

            if ($resp->failed()) {
                throw new RuntimeException('Meta: ' . $this->mensajeError($resp->json(), $resp->body()));
            }

            foreach ($resp->json('data', []) as $fila) {
                $out[] = $fila;
            }

            $url = $resp->json('paging.next');
        }

        return $out;
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
                $out[] = $this->normalizar($fila, $this->tiposConversion($cuenta));
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
    private function normalizar(array $fila, array $tiposConversion): array
    {
        $nombre = trim((string) ($fila['campaign_name'] ?? ''));

        return [
            'match_key'   => Linea::normalizar($nombre),
            'nombre'      => $nombre,
            'fecha'       => (string) ($fila['date_start'] ?? ''),
            'impresiones' => (int) ($fila['impressions'] ?? 0),
            'alcance'     => (int) ($fila['reach'] ?? 0),
            'clics'       => (int) ($fila['clicks'] ?? 0),
            'gasto_real'  => round((float) ($fila['spend'] ?? 0), 2), // en la moneda de la cuenta
            'leads'       => $this->leads($fila['actions'] ?? [], $tiposConversion),
        ];
    }

    /**
     * Leads de una fila de insights: el valor del PRIMER tipo de la lista de
     * prioridad que aparezca. Ver tiposConversion() para por qué no se suman.
     *
     * @param  array<int,array<string,mixed>>  $acciones
     * @param  array<int,string>  $tipos
     */
    private function leads(array $acciones, array $tipos): float
    {
        $porTipo = [];

        foreach ($acciones as $accion) {
            $tipo = (string) ($accion['action_type'] ?? '');

            if ($tipo !== '') {
                $porTipo[$tipo] = (float) ($accion['value'] ?? 0);
            }
        }

        foreach ($tipos as $tipo) {
            if (isset($porTipo[$tipo])) {
                return $porTipo[$tipo];
            }
        }

        return 0.0;
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

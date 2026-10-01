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
 * Conector con la Advertiser API de OpenAI (anuncios en ChatGPT).
 *
 * Mismo contrato que los demás: baja métricas de campaña día a día y las vuelca
 * en CampaignImporter::importRows().
 *
 * LO QUE HACE A OPENAI DISTINTO (y por qué es el conector más sencillo):
 *
 *  - No hay OAuth ni credenciales de agencia. Cada cuenta publicitaria genera
 *    su propia clave en Ads Manager (Settings) y esa clave SOLO ve esa cuenta.
 *    Se pega a mano en la ficha y no caduca.
 *  - Como la clave ya fija la cuenta, la URL no lleva su ID. El external_id se
 *    usa para comprobar al conectar que la clave es de la cuenta correcta.
 *  - Un único informe ya trae el nombre de la campaña, por día, con gasto en
 *    la moneda de la cuenta (no micros). Los presupuestos, en cambio, sí van
 *    en micros (1.000.000 = 1 €), y solo existen como límite total.
 *  - Los días son los de la zona horaria de la cuenta.
 *  - No hay alcance: se importa 0.
 *  - Una métrica que no se puede calcular llega como null, no como 0.
 *  - No admite rangos que acaben en el futuro, y "hasta hoy" acaba a la
 *    medianoche de mañana: el día en curso no se pide, entra en la siguiente
 *    sincronización (que re-baja varios días).
 */
class OpenAiAdsConnector implements Conector
{
    private const API = 'https://api.ads.openai.com/v1';

    /** Máximo de días por informe (límite de OpenAI). */
    private const DIAS_POR_TRAMO = 365;

    public function __construct(private CampaignImporter $importer)
    {
    }

    /**
     * Guarda la clave de API de la cuenta tras comprobar que funciona y que es
     * de la cuenta que toca: una clave de otro cliente importaría sus datos
     * aquí sin dar ningún error.
     */
    public function guardarApiKey(Cuenta $cuenta, string $apiKey): ConexionApi
    {
        $apiKey = trim($apiKey);

        $datos = $this->get('/ad_account', [], $apiKey);
        $id    = (string) ($datos['id'] ?? '');

        $esperado = trim((string) $cuenta->external_id);

        if ($esperado !== '' && $id !== '' && $esperado !== $id) {
            throw new RuntimeException(sprintf(
                'Esa clave es de la cuenta %s (%s), no de %s. Revisa que sea la clave de este cliente.',
                $id,
                (string) ($datos['name'] ?? '?'),
                $esperado
            ));
        }

        // Sin ID en la ficha, se rellena con el de la clave para que quede
        // a la vista a qué cuenta de OpenAI está conectada.
        if ($esperado === '' && $id !== '') {
            $cuenta->forceFill(['external_id' => $id])->save();
        }

        return ConexionApi::updateOrCreate(
            ['cuenta_id' => $cuenta->id],
            [
                'metodo'       => 'api_key',
                'access_token' => $apiKey,
                'expires_at'   => null,
                'ultimo_error' => null,
            ]
        );
    }

    public function sincronizar(Cuenta $cuenta, int $dias = 3): array
    {
        $dias = max(1, $dias);

        return $this->sincronizarRango(
            $cuenta,
            now()->subDays($dias)->toDateString(),
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
            throw new RuntimeException('Esta cuenta no tiene conexión con OpenAI. Conéctala desde su ficha en /admin.');
        }

        [$desde, $hasta] = $this->rango($desde, $hasta);

        try {
            $filas = $this->filas($conexion, $desde, $hasta);

            $res = $this->importer->importRows($cuenta, $filas, [
                'origen'  => 'api',
                'fichero' => sprintf('OpenAI Ads API · %s → %s', $desde, $hasta),
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
    public function filas(ConexionApi $conexion, string $desde, string $hasta): array
    {
        $out = [];

        foreach ($this->tramos($desde, $hasta) as [$ini, $fin]) {
            foreach ($this->insights($conexion, $ini, $fin) as $fila) {
                $nombre = trim((string) ($fila['campaign_name'] ?? ''));

                $out[] = [
                    'match_key'   => $nombre !== '' ? Linea::normalizar($nombre) : '',
                    'nombre'      => $nombre,
                    'fecha'       => (string) ($fila['readable_time'] ?? ''),
                    'impresiones' => (int) ($fila['impressions'] ?? 0),
                    'alcance'     => 0, // OpenAI no da alcance
                    'clics'       => (int) ($fila['clicks'] ?? 0),
                    'gasto_real'  => round((float) ($fila['spend'] ?? 0), 2),
                    // Conversiones de objetivo (clic + visualización, con las
                    // ventanas por defecto: 30 días clic, 1 día vista).
                    'leads'       => round((float) ($fila['conversions'] ?? 0), 2),
                ];
            }
        }

        return $out;
    }

    /**
     * Anota el límite de gasto de cada campaña como referencia interna.
     * OpenAI solo tiene límite total (lifetime), en micros.
     * NUNCA toca presupuesto_eventos.
     */
    public function refrescarPresupuestos(Cuenta $cuenta, ConexionApi $conexion): int
    {
        $lineas = $cuenta->lineas()->get()->keyBy('match_key');

        if ($lineas->isEmpty()) {
            return 0;
        }

        $tocadas = 0;

        foreach ($this->paginar('/campaigns', ['limit' => 500], $conexion) as $c) {
            $linea  = $lineas->get(Linea::normalizar((string) ($c['name'] ?? '')));
            $micros = $c['budget']['lifetime_spend_limit_micros'] ?? null;

            if (! $linea || ! is_numeric($micros) || (int) $micros <= 0) {
                continue;
            }

            $linea->forceFill([
                'presupuesto_plataforma'                => round((int) $micros / 1_000_000, 2),
                'presupuesto_plataforma_tipo'           => 'total',
                'presupuesto_plataforma_nivel'          => 'campania',
                'presupuesto_plataforma_actualizado_en' => now(),
            ])->save();

            $tocadas++;
        }

        return $tocadas;
    }

    /**
     * Informe diario por campaña de toda la cuenta. El rango va en la zona
     * horaria de la cuenta (no se manda timezone) y 'until' es inclusivo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function insights(ConexionApi $conexion, string $desde, string $hasta): array
    {
        return $this->paginar('/ad_account/insights', [
            'aggregation_level' => 'campaign',
            'time_granularity'  => 'daily',
            'time_ranges[]'     => json_encode(['type' => 'date_range', 'since' => $desde, 'until' => $hasta]),
            'fields[]'          => [
                'campaign_id', 'campaign_name', 'readable_time',
                'impressions', 'clicks', 'spend', 'conversions',
            ],
            'limit'             => 2000,
        ], $conexion);
    }

    /**
     * Listados con cursor: se pide la siguiente página con after=last_id
     * mientras has_more sea cierto.
     *
     * @param  array<string,mixed>  $params
     * @return array<int,array<string,mixed>>
     */
    private function paginar(string $ruta, array $params, ConexionApi $conexion): array
    {
        $todos = [];
        $after = null;

        do {
            $json  = $this->get($ruta, $after ? $params + ['after' => $after] : $params, (string) $conexion->access_token);
            $todos = array_merge($todos, (array) ($json['data'] ?? []));
            $after = (string) ($json['last_id'] ?? '');
        } while (($json['has_more'] ?? false) && $after !== '');

        return $todos;
    }

    /**
     * Trocea el rango en bloques de como mucho 365 días.
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
     * GET a la API. Los parámetros repetidos (fields[]=a&fields[]=b) se montan
     * a mano: Laravel los numeraría como fields[0]=a.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function get(string $ruta, array $params, string $apiKey): array
    {
        $query = [];
        foreach ($params as $clave => $valor) {
            foreach ((array) $valor as $v) {
                $query[] = rawurlencode($clave) . '=' . rawurlencode((string) $v);
            }
        }

        $url = self::API . $ruta . ($query ? '?' . implode('&', $query) : '');

        $resp = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 500, throw: false)
            ->get($url);

        if ($resp->failed()) {
            $json    = $resp->json();
            $mensaje = data_get($json, 'error.message') ?? $json['message'] ?? null;
            $texto   = is_string($mensaje) && $mensaje !== '' ? $mensaje : mb_substr($resp->body(), 0, 300);

            if ($resp->status() === 401) {
                $texto .= ' · La clave de API no es válida o se revocó: genera otra en Ads Manager → Settings y vuelve a pegarla en la ficha.';
            }

            throw new RuntimeException('OpenAI Ads: ' . $texto);
        }

        return (array) $resp->json();
    }

    /**
     * Rango válido para OpenAI: enderezado y terminando como tarde AYER, porque
     * pedir hoy hace que el rango acabe en el futuro y la API lo rechaza.
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

        $ayer = CarbonImmutable::now()->startOfDay()->subDay();

        if ($fin->gt($ayer)) {
            $fin = $ayer;
        }

        if ($ini->gt($fin)) {
            throw new RuntimeException('OpenAI solo da días completos: el día de hoy se traerá en la próxima sincronización.');
        }

        return [$ini->toDateString(), $fin->toDateString()];
    }
}

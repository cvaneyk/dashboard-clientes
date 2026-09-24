<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Services\Sync\MetaConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnóstico: qué tipos de conversión devuelve realmente una cuenta de Meta.
 *
 * Sirve para acertar con "qué cuenta como lead" en cada cliente. Unos usan
 * formularios de Lead Ads ('lead') y otros registros en su web con píxel
 * ('complete_registration'), y si se elige mal el informe sale con 0 leads.
 *
 * Ejemplo:
 *   php artisan portal:meta-acciones 3 --dias=30
 *
 * El tipo elegido se configura luego en la ficha de la Cuenta, en /admin.
 */
class PortalMetaAcciones extends Command
{
    protected $signature = 'portal:meta-acciones {cuenta : ID de la cuenta} {--dias=30 : Días hacia atrás a mirar}';

    protected $description = 'Lista los tipos de conversión que devuelve Meta para una cuenta (para configurar cuál cuenta como lead)';

    public function handle(MetaConnector $conector): int
    {
        $cuenta = Cuenta::with('conexionApi')->find($this->argument('cuenta'));

        if (! $cuenta || $cuenta->plataforma !== 'meta') {
            $this->error('No existe esa cuenta de Meta.');

            return self::FAILURE;
        }

        if (! $cuenta->conexionApi) {
            $this->error('Esa cuenta no está conectada a la API. Conéctala desde su ficha en /admin.');

            return self::FAILURE;
        }

        $dias  = max(1, (int) $this->option('dias'));
        $desde = now()->subDays($dias - 1)->toDateString();
        $hasta = now()->toDateString();

        $this->info("Mirando {$cuenta->nombre_cuenta} · {$desde} → {$hasta}…");

        $id = preg_replace('/^act_/', '', (string) $cuenta->external_id);

        $resp = Http::withToken($cuenta->conexionApi->access_token)
            ->timeout(60)
            ->get(sprintf(
                'https://graph.facebook.com/%s/act_%s/insights?%s',
                config('services.meta.api_version'),
                $id,
                http_build_query([
                    'level'      => 'campaign',
                    'fields'     => 'actions',
                    'time_range' => json_encode(['since' => $desde, 'until' => $hasta]),
                    'limit'      => 500,
                ])
            ));

        if ($resp->failed()) {
            $this->error('Meta: ' . ($resp->json('error.message') ?? $resp->body()));

            return self::FAILURE;
        }

        $totales = [];

        foreach ($resp->json('data', []) as $fila) {
            foreach (($fila['actions'] ?? []) as $accion) {
                $tipo = (string) ($accion['action_type'] ?? '');

                if ($tipo !== '') {
                    $totales[$tipo] = ($totales[$tipo] ?? 0) + (float) ($accion['value'] ?? 0);
                }
            }
        }

        if ($totales === []) {
            $this->warn('Meta no ha devuelto ninguna conversión en ese periodo.');

            return self::SUCCESS;
        }

        arsort($totales);

        $enUso = $conector->tiposConversion($cuenta);

        $this->table(
            ['Tipo de acción', 'Total en el periodo', '¿Se usa como lead?'],
            collect($totales)->map(fn ($total, $tipo) => [
                $tipo,
                rtrim(rtrim(number_format($total, 2, ',', '.'), '0'), ','),
                in_array($tipo, $enUso, true)
                    ? 'sí (prioridad ' . (array_search($tipo, $enUso, true) + 1) . ')'
                    : '—',
            ])->values()->all()
        );

        $this->newLine();
        $this->line('Lista en uso para esta cuenta (se aplica el primero que aparezca en cada fila):');
        $this->line('  ' . implode(', ', $enUso));
        $this->line('Para cambiarla: ficha de la Cuenta en /admin → "Qué cuenta como lead".');

        return self::SUCCESS;
    }
}

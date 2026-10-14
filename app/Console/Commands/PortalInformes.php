<?php

namespace App\Console\Commands;

use App\Mail\InformeMail;
use App\Models\Agencia;
use App\Models\Cliente;
use App\Services\Informes\InformesSemanales;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía los informes semanales por email: el de cada cliente (a los emails de
 * su ficha) y el resumen interno de todas las cuentas para administración.
 * El scheduler lo lanza los lunes (routes/console.php) con la semana anterior.
 *
 * Ejemplos:
 *   php artisan portal:informes                         → todo lo programado
 *   php artisan portal:informes --solo=admin            → solo el resumen interno
 *   php artisan portal:informes --cliente=mi-universidad --para=yo@example.com
 *                                                       → prueba, a otra dirección
 *   php artisan portal:informes --fecha=2026-09-29      → la semana anterior a esa fecha
 */
class PortalInformes extends Command
{
    protected $signature = 'portal:informes
        {--cliente= : ID o slug de un cliente concreto (no envía el resumen interno)}
        {--solo= : "clientes" o "admin"}
        {--para= : Envía a esta dirección en lugar de a los destinatarios reales}
        {--fecha= : Fecha de referencia AAAA-MM-DD (se informa la semana anterior)}
        {--forzar : Envía aunque no haya actividad o ya se haya enviado hoy}';

    protected $description = 'Envía por email los informes semanales a clientes y administración';

    public function handle(InformesSemanales $informes): int
    {
        [$desde, $hasta] = InformesSemanales::semanaAnterior(
            $this->option('fecha') ? Carbon::parse($this->option('fecha')) : null
        );
        $this->info('Semana: ' . InformesSemanales::periodo($desde, $hasta));

        $para   = $this->option('para');
        $forzar = (bool) $this->option('forzar');
        $solo   = $this->option('solo');
        $fallos = 0;

        if ($para && ! filter_var($para, FILTER_VALIDATE_EMAIL)) {
            $this->error("--para no es un email válido: {$para}");
            return self::FAILURE;
        }

        if ($solo !== 'admin') {
            $fallos += $this->enviarClientes($informes, $desde, $hasta, $para, $forzar);
        }

        if ($solo !== 'clientes' && ! $this->option('cliente')) {
            $fallos += $this->enviarAdmin($informes, $desde, $hasta, $para);
        }

        return $fallos ? self::FAILURE : self::SUCCESS;
    }

    private function enviarClientes(InformesSemanales $informes, Carbon $desde, Carbon $hasta, ?string $para, bool $forzar): int
    {
        $filtro = $this->option('cliente');

        $clientes = $filtro
            ? Cliente::where('id', $filtro)->orWhere('slug', $filtro)->get()
            : Cliente::where('activo', true)->where('informe_semanal', true)
                // Una agencia desactivada deja de enviar a sus clientes.
                ->where(fn ($q) => $q->whereNull('agencia_id')
                    ->orWhereIn('agencia_id', Agencia::where('activo', true)->select('id')))
                ->get();

        if ($filtro && $clientes->isEmpty()) {
            $this->error("No existe el cliente {$filtro}");
            return 1;
        }

        $fallos = 0;
        $hoy    = Carbon::now('Europe/Madrid')->startOfDay();

        foreach ($clientes as $cliente) {
            $destinos = $para ? [$para] : $cliente->emailsInforme();

            if (! $destinos) {
                $this->warn("{$cliente->nombre}: sin email válido, se omite.");
                continue;
            }

            // Evita duplicados si el scheduler se lanza dos veces el mismo día.
            if (! $para && ! $forzar && $cliente->ultimo_informe_en?->greaterThanOrEqualTo($hoy)) {
                $this->line("{$cliente->nombre}: ya enviado hoy, se omite (usa --forzar).");
                continue;
            }

            try {
                $informe = $informes->paraCliente($cliente, $desde, $hasta);

                if ($informe['sin_actividad'] && ! $forzar) {
                    $this->line("{$cliente->nombre}: sin actividad en la semana, no se envía.");
                    continue;
                }

                Mail::to($destinos)->send(new InformeMail($informe['asunto'], $informe['cuerpo'], $cliente->color, $cliente->agencia_id));

                if (! $para) {
                    $cliente->forceFill(['ultimo_informe_en' => now()])->save();
                }

                $this->info("{$cliente->nombre}: enviado a " . implode(', ', $destinos));
            } catch (Throwable $e) {
                $fallos++;
                $this->error("{$cliente->nombre}: " . $e->getMessage());
                report($e);
            }
        }

        return $fallos;
    }

    /**
     * Un resumen interno por agencia activa, cada uno solo con sus cuentas y a
     * sus destinatarios. Si quedan clientes sin agencia (instalación anterior
     * a las agencias), van en un resumen aparte para los admins sin agencia.
     */
    private function enviarAdmin(InformesSemanales $informes, Carbon $desde, Carbon $hasta, ?string $para): int
    {
        $grupos = Agencia::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all();

        $fallos = 0;

        foreach ($grupos as $agenciaId => $nombre) {
            $fallos += $this->enviarResumen($informes, $desde, $hasta, $para, $agenciaId, "Resumen interno de {$nombre}");
        }

        if (Cliente::whereNull('agencia_id')->exists()) {
            $fallos += $this->enviarResumen($informes, $desde, $hasta, $para, null, 'Resumen interno');
        }

        return $fallos;
    }

    private function enviarResumen(InformesSemanales $informes, Carbon $desde, Carbon $hasta, ?string $para, ?int $agenciaId, string $etiqueta): int
    {
        $destinos = $para ? [$para] : InformesSemanales::destinatariosAdmin($agenciaId);

        if (! $destinos) {
            $this->warn("{$etiqueta}: no hay destinatarios (ni email de informes ni usuarios admin con email válido).");
            return 0;
        }

        try {
            $informe = $informes->paraAdmin($desde, $hasta, $agenciaId);
            Mail::to($destinos)->send(new InformeMail($informe['asunto'], $informe['cuerpo'], null, $agenciaId));
            $this->info("{$etiqueta}: enviado a " . implode(', ', $destinos));

            return 0;
        } catch (Throwable $e) {
            $this->error("{$etiqueta}: " . $e->getMessage());
            report($e);

            return 1;
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos piloto: Clínica Universitaria La Salle · Meta · junio 2026.
 * Sirve para ver el dashboard funcionando de punta a punta EN LOCAL.
 *
 * SOLO PARA DESARROLLO. No se llama desde DatabaseSeeder a propósito: el deploy
 * ejecuta `db:seed --force` en cada push y esto metía un cliente inventado, con
 * su usuario y una contraseña conocida, en la base de datos de producción.
 * Por eso se niega a ejecutarse si el entorno es de producción.
 *
 * Ejecutar:  php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
 *
 * Acceso cliente demo:  lasalle@olimedia.es  /  lasalle2026
 */
class PortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('PortalDemoSeeder no se ejecuta en producción: son datos inventados.');

            return;
        }

        // --- Cliente + usuario de acceso ---
        $cliente = Cliente::updateOrCreate(
            ['slug' => 'la-salle'],
            ['nombre' => 'Clínica Universitaria La Salle', 'color' => '#638E76', 'activo' => true]
        );

        User::updateOrCreate(
            ['email' => 'lasalle@olimedia.es'],
            [
                'name'       => 'Clínica La Salle',
                'password'   => Hash::make('lasalle2026'),
                'cliente_id' => $cliente->id,
                'es_admin'   => false,
            ]
        );

        // --- Cuenta publicitaria (Meta) ---
        $cuenta = Cuenta::updateOrCreate(
            ['cliente_id' => $cliente->id, 'plataforma' => 'meta', 'nombre_cuenta' => 'Clínica Universitaria La Salle'],
            ['activo' => true]
        );

        // --- Líneas (campañas) con su objetivo-cliente de junio y sus reales de mes ---
        // [nombre, producto, objetivo_cliente_junio, gasto_real_mes, clics_mes, leads_mes, imp_mes]
        $defs = [
            ['🟥 INFANTIL_LEADADS',      'Infantil',        1200, 610, 1860,  56, 100000],
            ['🟩 NEURO_LEADADS',         'Neuro',           1200, 605, 1260,  36,  44500],
            ['🟨 FISIO_LEADADS',         'Fisioterapia',    1100, 555, 2470, 154,  83700],
            ['🟦 PODO_LEADADS',          'Podología',        700, 331, 1878, 129,  55000],
            ['🟧 TERAPIADEMANO_LEADADS', 'Terapia de mano',  400, 152,  358,   8,  16700],
        ];

        $mes    = CarbonImmutable::create(2026, 6, 1);
        $dias   = $mes->daysInMonth;

        foreach ($defs as $i => $d) {
            [$nombre, $producto, $objetivo, $gastoMes, $clicsMes, $leadsMes, $impMes] = $d;

            $linea = Linea::updateOrCreate(
                ['cuenta_id' => $cuenta->id, 'match_key' => Linea::normalizar($nombre)],
                [
                    'cliente_id' => $cliente->id,
                    'plataforma' => 'meta',
                    'nombre'     => $nombre,
                    'producto'   => $producto,
                    'nivel'      => 'campania',
                    'activo'     => true,
                ]
            );

            // Presupuesto-cliente de junio (un único evento a día 1).
            PresupuestoEvento::updateOrCreate(
                ['linea_id' => $linea->id, 'fecha_efectiva' => $mes->toDateString()],
                ['objetivo_mes' => $objetivo, 'estado' => 'activa', 'nota' => 'Alta de mes']
            );

            // Reparto de las métricas REALES a lo largo de los 30 días, con
            // variación diaria y un pico controlado en FISIO (día 18) para
            // demostrar la detección de picos.
            $pesos = [];
            $suma  = 0.0;
            for ($day = 1; $day <= $dias; $day++) {
                $w = 1 + 0.35 * sin($day / 2.2 + $i);      // ondulación
                if ($producto === 'Fisioterapia' && $day === 18) {
                    $w *= 3.2;                              // pico
                }
                $pesos[$day] = max($w, 0.15);
                $suma += $pesos[$day];
            }

            foreach ($pesos as $day => $w) {
                $frac  = $w / $suma;
                $fecha = $mes->addDays($day - 1)->toDateString();

                MetricaDiaria::updateOrCreate(
                    ['cuenta_id' => $cuenta->id, 'match_key' => $linea->match_key, 'fecha' => $fecha],
                    [
                        'cliente_id'  => $cliente->id,
                        'linea_id'    => $linea->id,
                        'plataforma'  => 'meta',
                        'impresiones' => (int) round($impMes * $frac),
                        'alcance'     => (int) round($impMes * $frac * 0.42),
                        'clics'       => (int) round($clicsMes * $frac),
                        'gasto_real'  => round($gastoMes * $frac, 2),
                        'leads'       => round($leadsMes * $frac, 2),
                        'extra'       => null,
                    ]
                );
            }
        }

        $this->command?->info('Piloto La Salle cargado. Cliente demo: lasalle@olimedia.es / lasalle2026');
    }
}

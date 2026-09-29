<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo de facturación de una línea: entre dos fechas, al cliente se le cobra
 * el gasto real de la plataforma más un porcentaje.
 *
 * La tabla se sigue llamando presupuesto_eventos por historia, pero sus filas
 * ya NO son eventos puntuales: son intervalos con principio y (opcionalmente) fin.
 */
class PresupuestoEvento extends Model
{
    protected $table = 'presupuesto_eventos';
    protected $fillable = ['linea_id', 'desde', 'hasta', 'porcentaje', 'estado', 'nota'];
    protected $casts = [
        // 'date:Y-m-d' y no 'date': con 'date' se guardaba 'Y-m-d H:i:s' y las
        // búsquedas por fecha exacta dejaban de casar fuera de MySQL.
        'desde'      => 'date:Y-m-d',
        'hasta'      => 'date:Y-m-d',
        'porcentaje' => 'decimal:2',
    ];

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class);
    }

    /** ¿Cubre este tramo la fecha dada? Sin 'hasta', el tramo sigue abierto. */
    public function cubre(CarbonInterface $fecha): bool
    {
        if (! $this->desde || $this->desde->gt($fecha)) {
            return false;
        }

        return ! $this->hasta || $this->hasta->gte($fecha);
    }

    /** Multiplicador a aplicar sobre el gasto real: 25 % → 1.25. */
    public function multiplicador(): float
    {
        return 1 + ((float) $this->porcentaje / 100);
    }
}

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

    /**
     * Cuál de los tramos manda en una fecha. Es la ÚNICA definición de la regla:
     * la usan el motor de precios y el panel, para que nunca discrepen.
     *
     *   1. Si algún tramo que cubre ese día está pausado, manda la pausa. Pausar
     *      tiene que poder hacerse añadiendo un tramo encima; si ganara el
     *      porcentaje más alto, una pausa nunca llegaría a aplicarse.
     *   2. Entre los activos, gana el PORCENTAJE MÁS ALTO. A igualdad, el
     *      creado después.
     *
     * Consecuencia a tener presente: para BAJAR el margen no basta con añadir un
     * tramo menor encima; hay que corregir o borrar el anterior.
     *
     * @param  \Illuminate\Support\Collection<int,self>  $tramos
     */
    public static function vigenteEn($tramos, CarbonInterface $fecha): ?self
    {
        $cubren = $tramos->filter(fn (self $t) => $t->cubre($fecha));

        if ($cubren->isEmpty()) {
            return null;
        }

        $pausados = $cubren->where('estado', 'pausada');

        if ($pausados->isNotEmpty()) {
            return $pausados->sortBy('id')->last();
        }

        return $cubren->sortBy([
            ['porcentaje', 'asc'],
            ['id', 'asc'],
        ])->last();
    }
}

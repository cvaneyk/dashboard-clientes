<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresupuestoEvento extends Model
{
    protected $table = 'presupuesto_eventos';
    protected $fillable = ['linea_id', 'fecha_efectiva', 'objetivo_mes', 'estado', 'nota'];
    protected $casts = [
        // 'date:Y-m-d' por el mismo motivo que en MetricaDiaria: con 'date' se
        // guardaba 'Y-m-d H:i:s' y las búsquedas por fecha exacta (el alta
        // masiva de objetivos) dejaban de casar fuera de MySQL.
        'fecha_efectiva' => 'date:Y-m-d',
        'objetivo_mes'   => 'decimal:2',
    ];

    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

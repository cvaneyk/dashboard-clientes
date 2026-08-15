<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresupuestoEvento extends Model
{
    protected $table = 'presupuesto_eventos';
    protected $fillable = ['linea_id', 'fecha_efectiva', 'objetivo_mes', 'estado', 'nota'];
    protected $casts = [
        'fecha_efectiva' => 'date',
        'objetivo_mes'   => 'decimal:2',
    ];

    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

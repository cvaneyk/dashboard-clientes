<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricaDiaria extends Model
{
    protected $table = 'metricas_diarias';
    protected $fillable = [
        'cliente_id', 'cuenta_id', 'linea_id', 'plataforma', 'match_key',
        'fecha', 'impresiones', 'alcance', 'clics', 'gasto_real', 'leads',
        'extra', 'import_id',
    ];
    protected $casts = [
        'fecha'      => 'date',
        'gasto_real' => 'decimal:2',
        'leads'      => 'decimal:2',
        'extra'      => 'array',
    ];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(Cuenta::class); }
    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

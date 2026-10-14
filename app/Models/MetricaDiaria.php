<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricaDiaria extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $query->whereIn($query->qualifyColumn('cliente_id'), static::idsClientes($agenciaId));
    }

    protected $table = 'metricas_diarias';
    protected $fillable = [
        'cliente_id', 'cuenta_id', 'linea_id', 'plataforma', 'match_key',
        'fecha', 'impresiones', 'alcance', 'clics', 'gasto_real', 'leads',
        'extra', 'import_id',
    ];
    protected $casts = [
        // 'date:Y-m-d' (no 'date') para que se guarde exactamente como Y-m-d.
        // Con 'date' se escribía 'Y-m-d H:i:s': MySQL lo truncaba por ser columna
        // DATE, pero en otros motores queda la hora pegada y entonces el upsert
        // por (cuenta_id, match_key, fecha) deja de casar y revienta el índice único.
        'fecha'      => 'date:Y-m-d',
        'gasto_real' => 'decimal:2',
        'leads'      => 'decimal:2',
        'extra'      => 'array',
    ];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(Cuenta::class); }
    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

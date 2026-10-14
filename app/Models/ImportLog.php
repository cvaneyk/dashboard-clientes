<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $query->whereIn($query->qualifyColumn('cliente_id'), static::idsClientes($agenciaId));
    }

    protected $table = 'import_logs';
    protected $fillable = [
        'cliente_id', 'plataforma', 'origen', 'fichero', 'periodo_inicio', 'periodo_fin',
        'filas_ok', 'filas_error', 'resumen', 'user_id',
    ];
    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fin'    => 'date',
        'resumen'        => 'array',
    ];

    /**
     * Al eliminar una importación, se borran también las métricas que cargó
     * (para poder "deshacer" un informe mal subido y volver a importarlo limpio).
     */
    protected static function booted(): void
    {
        static::deleting(function (ImportLog $log) {
            MetricaDiaria::where('import_id', $log->id)->delete();
        });
    }
}

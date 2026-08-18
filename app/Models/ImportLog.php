<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'import_logs';
    protected $fillable = [
        'cliente_id', 'plataforma', 'fichero', 'periodo_inicio', 'periodo_fin',
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

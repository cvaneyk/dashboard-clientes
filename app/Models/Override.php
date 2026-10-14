<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Override extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $query->whereIn($query->qualifyColumn('linea_id'), static::idsLineas($agenciaId));
    }

    protected $table = 'overrides';
    protected $fillable = ['linea_id', 'fecha', 'campo', 'valor', 'motivo', 'user_id'];
    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

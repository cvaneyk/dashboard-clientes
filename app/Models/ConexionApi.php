<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenciales de la API de una Cuenta publicitaria.
 * Hoy solo Meta; Google y TikTok reutilizarán esta tabla.
 */
class ConexionApi extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $query->whereIn($query->qualifyColumn('cuenta_id'), static::idsCuentas($agenciaId));
    }

    protected $table = 'conexiones_api';
    protected $fillable = [
        'cuenta_id', 'metodo', 'access_token', 'refresh_token', 'login_customer_id',
        'expires_at', 'ultima_sincronizacion', 'ultimo_error',
    ];
    protected $casts = [
        'access_token'          => 'encrypted',
        'refresh_token'         => 'encrypted',
        'expires_at'            => 'datetime',
        'ultima_sincronizacion' => 'datetime',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}

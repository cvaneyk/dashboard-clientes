<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenciales de la API de una Cuenta publicitaria.
 * Hoy solo Meta; Google y TikTok reutilizarán esta tabla.
 */
class ConexionApi extends Model
{
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

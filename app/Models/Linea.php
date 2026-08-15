<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Linea extends Model
{
    protected $table = 'lineas';
    protected $fillable = [
        'cliente_id', 'cuenta_id', 'plataforma', 'nombre',
        'producto', 'match_key', 'nivel', 'activo',
    ];
    protected $casts = ['activo' => 'boolean'];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(Cuenta::class); }
    public function presupuestoEventos(): HasMany { return $this->hasMany(PresupuestoEvento::class); }
    public function metricas(): HasMany { return $this->hasMany(MetricaDiaria::class); }
    public function overrides(): HasMany { return $this->hasMany(Override::class); }

    /** Normaliza un nombre de campaña/adset para emparejar (quita emojis y espacios). */
    public static function normalizar(string $nombre): string
    {
        // Quita emojis y símbolos, colapsa espacios, minúsculas.
        $limpio = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $nombre);
        $limpio = trim(preg_replace('/\s+/', ' ', (string) $limpio));
        return Str::lower($limpio);
    }
}

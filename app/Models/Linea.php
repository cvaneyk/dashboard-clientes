<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
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
        'presupuesto_plataforma', 'presupuesto_plataforma_tipo',
        'presupuesto_plataforma_nivel', 'presupuesto_plataforma_actualizado_en',
    ];
    protected $casts = [
        'activo'                                => 'boolean',
        'presupuesto_plataforma'                => 'decimal:2',
        'presupuesto_plataforma_actualizado_en' => 'datetime',
    ];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(Cuenta::class); }
    public function presupuestoEventos(): HasMany { return $this->hasMany(PresupuestoEvento::class); }
    public function metricas(): HasMany { return $this->hasMany(MetricaDiaria::class); }
    public function overrides(): HasMany { return $this->hasMany(Override::class); }

    /**
     * Evento de presupuesto que rige en una fecha: el último con fecha_efectiva
     * anterior o igual. Es el mismo criterio que aplica PricingEngine día a día.
     * Devuelve null si la línea aún no tiene presupuesto asignado.
     *
     * Trabaja sobre la relación ya cargada, así que en listados conviene hacer
     * ->with('presupuestoEventos') para no disparar una consulta por fila.
     */
    public function eventoVigente(?CarbonInterface $fecha = null): ?PresupuestoEvento
    {
        $fecha ??= Carbon::now();

        return PresupuestoEvento::vigenteEn($this->presupuestoEventos, $fecha);
    }

    /** Normaliza un nombre de campaña/adset para emparejar (quita emojis y espacios). */
    public static function normalizar(string $nombre): string
    {
        // Quita emojis y símbolos (flechas, geométricos, pictogramas, selectores
        // de variación y ZWJ), colapsa espacios y pasa a minúsculas.
        $limpio = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u',
            '',
            $nombre
        );
        $limpio = trim(preg_replace('/\s+/', ' ', (string) $limpio));
        return Str::lower($limpio);
    }
}

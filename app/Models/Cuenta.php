<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cuenta extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $query->whereIn($query->qualifyColumn('cliente_id'), static::idsClientes($agenciaId));
    }

    /**
     * Plataformas que puede tener una cuenta, con su nombre visible.
     *
     * Única fuente de verdad: la usan los formularios, los filtros y el
     * dashboard. Antes estaba copiada en cinco sitios y cada plataforma nueva
     * se olvidaba en alguno.
     *
     * Tener conector de API es independiente: eso lo dice Conectores::PLATAFORMAS.
     * Una plataforma de esta lista sin conector se alimenta con informes subidos
     * a mano, que es como empezaron todas.
     */
    public const PLATAFORMAS = [
        'meta'     => 'Meta Ads',
        'google'   => 'Google Ads',
        'tiktok'   => 'TikTok Ads',
        'linkedin' => 'LinkedIn Ads',
        'spotify'  => 'Spotify Ads',
        'openai'   => 'OpenAI Ads',
    ];

    protected $table = 'cuentas';
    protected $fillable = [
        'cliente_id', 'plataforma', 'nombre_cuenta', 'external_id',
        'tipos_conversion', 'activo',
    ];
    protected $casts = ['activo' => 'boolean'];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function lineas(): HasMany { return $this->hasMany(Linea::class); }
    public function conexionApi(): HasOne { return $this->hasOne(ConexionApi::class); }
}

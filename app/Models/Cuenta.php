<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuenta extends Model
{
    protected $table = 'cuentas';
    protected $fillable = ['cliente_id', 'plataforma', 'nombre_cuenta', 'external_id', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class); }
    public function lineas(): HasMany { return $this->hasMany(Linea::class); }
}

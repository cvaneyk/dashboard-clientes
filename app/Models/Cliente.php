<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    protected $fillable = ['nombre', 'slug', 'logo', 'color', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function cuentas(): HasMany { return $this->hasMany(Cuenta::class); }
    public function lineas(): HasMany { return $this->hasMany(Linea::class); }
    public function usuarios(): HasMany { return $this->hasMany(User::class); }
}

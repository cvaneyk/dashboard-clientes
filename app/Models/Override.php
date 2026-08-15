<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Override extends Model
{
    protected $table = 'overrides';
    protected $fillable = ['linea_id', 'fecha', 'campo', 'valor', 'motivo', 'user_id'];
    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    public function linea(): BelongsTo { return $this->belongsTo(Linea::class); }
}

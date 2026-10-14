<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use App\Support\AgenciaActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use DeAgencia;

    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        static::porColumnaAgencia($query, $agenciaId);
    }

    protected $table = 'clientes';
    protected $fillable = ['agencia_id', 'nombre', 'slug', 'logo', 'color', 'activo', 'email_informes', 'informe_semanal', 'ultimo_informe_en'];
    protected $casts = ['activo' => 'boolean', 'informe_semanal' => 'boolean', 'ultimo_informe_en' => 'datetime'];

    protected static function booted(): void
    {
        // El admin de una agencia no elige agencia en el formulario: el
        // cliente que crea es siempre de la suya.
        static::creating(function (Cliente $cliente) {
            if ($cliente->agencia_id === null && AgenciaActual::filtra()) {
                $cliente->agencia_id = AgenciaActual::id();
            }
        });
    }

    public function agencia(): BelongsTo { return $this->belongsTo(Agencia::class); }
    public function cuentas(): HasMany { return $this->hasMany(Cuenta::class); }
    public function lineas(): HasMany { return $this->hasMany(Linea::class); }
    public function usuarios(): HasMany { return $this->hasMany(User::class); }

    /**
     * Destinatarios del informe semanal. El campo admite varios separados por
     * coma, punto y coma o espacios; los que no son un email válido se ignoran.
     *
     * @return list<string>
     */
    public function emailsInforme(): array
    {
        return self::separarEmails($this->email_informes);
    }

    /** @return list<string> */
    public static function separarEmails(?string $texto): array
    {
        return collect(preg_split('/[\s,;]+/', (string) $texto, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}

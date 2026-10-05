<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    protected $fillable = ['nombre', 'slug', 'logo', 'color', 'activo', 'email_informes', 'informe_semanal', 'ultimo_informe_en'];
    protected $casts = ['activo' => 'boolean', 'informe_semanal' => 'boolean', 'ultimo_informe_en' => 'datetime'];

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

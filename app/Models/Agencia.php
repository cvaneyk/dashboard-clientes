<?php

namespace App\Models;

use App\Models\Concerns\DeAgencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agencia que usa el portal: tiene sus clientes, sus usuarios, su marca y sus
 * propias apps de API (Meta, Google, LinkedIn, Spotify).
 *
 * Las credenciales de las apps van cifradas en `credenciales` (APP_KEY) y se
 * cargan en config('services.*') justo antes de hablar con la plataforma:
 * así los conectores siguen leyendo config() y no saben nada de agencias.
 */
class Agencia extends Model
{
    use DeAgencia;

    /** Clave en `credenciales` → ruta en config() que leen los conectores. */
    public const CREDENCIALES = [
        'meta_app_id'              => 'services.meta.app_id',
        'meta_app_secret'          => 'services.meta.app_secret',
        'google_client_id'         => 'services.google_ads.client_id',
        'google_client_secret'     => 'services.google_ads.client_secret',
        'google_login_customer_id' => 'services.google_ads.login_customer_id',
        'linkedin_client_id'       => 'services.linkedin_ads.client_id',
        'linkedin_client_secret'   => 'services.linkedin_ads.client_secret',
        'spotify_client_id'        => 'services.spotify_ads.client_id',
        'spotify_client_secret'    => 'services.spotify_ads.client_secret',
    ];

    protected $table = 'agencias';
    protected $fillable = [
        'nombre', 'slug', 'activo', 'logo', 'logo_pie', 'web', 'eslogan',
        'email_informes', 'credenciales',
    ];
    protected $casts = [
        'activo'       => 'boolean',
        'credenciales' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        // Cada agencia edita sus propios textos: arranca con una copia de las
        // plantillas por defecto (las que no tienen agencia).
        static::created(function (Agencia $agencia) {
            PlantillaEmail::withoutGlobalScopes()->whereNull('agencia_id')->get()
                ->each(fn (PlantillaEmail $p) => PlantillaEmail::withoutGlobalScopes()->create([
                    'agencia_id' => $agencia->id,
                    'clave'      => $p->clave,
                    'nombre'     => $p->nombre,
                    'asunto'     => $p->asunto,
                    'cuerpo'     => $p->cuerpo,
                ]));
        });
    }

    /** Un usuario de agencia solo ve la suya; uno sin agencia, ninguna. */
    protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void
    {
        $agenciaId === null
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($agenciaId);
    }

    public function clientes(): HasMany { return $this->hasMany(Cliente::class); }
    public function usuarios(): HasMany { return $this->hasMany(User::class); }

    // ------------------------------------------------------- credenciales

    /**
     * Carga las apps de esta agencia en config(). Lo que la agencia no tenga
     * queda VACÍO a propósito: nunca hereda las del .env ni las de otra
     * agencia cargada antes en el mismo proceso (el scheduler las recorre).
     */
    public function usarCredenciales(): void
    {
        $propias = (array) $this->credenciales;

        foreach (self::CREDENCIALES as $clave => $ruta) {
            config([$ruta => filled($propias[$clave] ?? null) ? $propias[$clave] : null]);
        }
    }

    /**
     * Carga las apps de la agencia dueña de esa cuenta publicitaria. Una cuenta
     * sin agencia (instalación anterior a las agencias) sigue con las del .env.
     */
    public static function usarCredencialesDe(Cuenta $cuenta): void
    {
        $agenciaId = Cliente::withoutGlobalScopes()->whereKey($cuenta->cliente_id)->value('agencia_id');

        if ($agenciaId) {
            static::withoutGlobalScopes()->find($agenciaId)?->usarCredenciales();
        }
    }

    // -------------------------------------------------------------- marca

    /** Pone la marca de esta agencia en config('marca.*'), que leen las vistas y el panel. */
    public function aplicarMarca(): void
    {
        foreach ($this->datosMarca() as $clave => $valor) {
            config(['marca.' . $clave => $valor]);
        }
    }

    /** @return array{nombre:string,logo:?string,logo_pie:?string,web:?string,eslogan:?string} */
    public function datosMarca(): array
    {
        return [
            'nombre'   => $this->nombre,
            'logo'     => $this->urlImagen($this->logo, 'logo'),
            'logo_pie' => $this->urlImagen($this->logo_pie, 'logo_pie'),
            'web'      => $this->web,
            'eslogan'  => $this->eslogan,
        ];
    }

    /**
     * Marca de una agencia sin tocar config(): para los emails, que se pueden
     * enviar desde la sesión de alguien de otra agencia (el superadmin).
     * Sin agencia, la de la plataforma.
     *
     * @return array{nombre:?string,logo:?string,logo_pie:?string,web:?string,eslogan:?string}
     */
    public static function marcaDe(?int $agenciaId): array
    {
        $agencia = $agenciaId ? static::withoutGlobalScopes()->find($agenciaId) : null;

        return $agencia
            ? $agencia->datosMarca()
            : array_intersect_key((array) config('marca'), array_flip(['nombre', 'logo', 'logo_pie', 'web', 'eslogan']));
    }

    /**
     * Logo subido desde el panel → ruta propia (sin depender de storage:link,
     * como el logo de los clientes). Una URL externa se usa tal cual.
     */
    private function urlImagen(?string $valor, string $tipo): ?string
    {
        if (blank($valor)) {
            return null;
        }

        return str_starts_with($valor, 'http')
            ? $valor
            : route('agencia.logo', ['agencia' => $this->id, 'tipo' => $tipo]);
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\AgenciaActual;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'agencia_id', 'cliente_id', 'es_admin', 'es_superadmin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_admin' => 'boolean',
            'es_superadmin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Un usuario de cliente es siempre de la agencia de su cliente.
        static::saving(function (User $user) {
            if ($user->cliente_id) {
                $user->agencia_id = Cliente::withoutGlobalScopes()->whereKey($user->cliente_id)->value('agencia_id');
            }
        });

        // El admin de una agencia solo puede crear usuarios de la suya. Solo al
        // crear: en un guardado cualquiera (p. ej. el remember_token al iniciar
        // sesión) quien esté autenticado no tiene por qué ser el de esa agencia.
        static::creating(function (User $user) {
            if (! $user->cliente_id && ! $user->es_superadmin && AgenciaActual::filtra()) {
                $user->agencia_id = AgenciaActual::id();
            }
        });
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Una agencia desactivada deja fuera a todos sus usuarios (salvo al superadmin). */
    public function agenciaActiva(): bool
    {
        if ($this->es_superadmin || $this->agencia_id === null) {
            return true;
        }

        return (bool) Agencia::withoutGlobalScopes()->whereKey($this->agencia_id)->value('activo');
    }

    /**
     * Clientes cuyo dashboard puede ver este usuario.
     *
     * Es la ÚNICA fuente de verdad del alcance del portal: el selector de
     * cliente se dibuja con esta lista y el cliente pedido por URL se valida
     * contra ella. Ampliar el acceso (una cartera por usuario, un permiso de
     * solo lectura) es cambiar este método, no los controladores.
     *
     * Un admin ve los clientes activos de SU agencia (el superadmin, los de
     * todas), que son los mismos datos que ya tiene en /admin: esto no le da
     * acceso nuevo, se los enseña como los ve el cliente.
     *
     * Se filtra aquí explícitamente y sin global scope, para que el resultado
     * no dependa de quién esté autenticado al llamar.
     *
     * @return Collection<int,Cliente>
     */
    public function clientesVisibles(): Collection
    {
        if (! $this->agenciaActiva()) {
            return collect();
        }

        $clientes = Cliente::withoutGlobalScopes()->where('activo', true)->orderBy('nombre');

        if ($this->es_superadmin) {
            return $clientes->get();
        }

        if ($this->es_admin) {
            // where(col, null) es "IS NULL": un admin sin agencia ve solo los
            // clientes sin agencia, igual que el global scope.
            return $clientes->where('agencia_id', $this->agencia_id)->get();
        }

        $propio = $this->cliente_id ? Cliente::withoutGlobalScopes()->find($this->cliente_id) : null;

        return $propio ? collect([$propio]) : collect();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->es_superadmin || ($this->es_admin && $this->agenciaActiva());
    }
}

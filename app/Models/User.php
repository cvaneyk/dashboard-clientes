<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['name', 'email', 'password', 'cliente_id', 'es_admin'])]
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
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Clientes cuyo dashboard puede ver este usuario.
     *
     * Es la ÚNICA fuente de verdad del alcance del portal: el selector de
     * cliente se dibuja con esta lista y el cliente pedido por URL se valida
     * contra ella. Ampliar el acceso (una cartera por usuario, un permiso de
     * solo lectura) es cambiar este método, no los controladores.
     *
     * Un admin ve todos los clientes activos, que son los mismos datos que ya
     * tiene en /admin: esto no le da acceso nuevo, se los enseña como los ve
     * el cliente.
     *
     * @return Collection<int,Cliente>
     */
    public function clientesVisibles(): Collection
    {
        if ($this->es_admin) {
            return Cliente::where('activo', true)->orderBy('nombre')->get();
        }

        return $this->cliente ? collect([$this->cliente]) : collect();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->es_admin;
    }
}

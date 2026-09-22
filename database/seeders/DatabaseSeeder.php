<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeder de arranque: deja el panel accesible en una instalación nueva.
 *
 * CUIDADO AL TOCAR ESTO: el deploy de Plesk lanza `db:seed --force` en CADA push,
 * así que todo lo que haya aquí se ejecuta contra PRODUCCIÓN una y otra vez. Solo
 * puede contener operaciones que sea seguro repetir y que no pisen datos reales.
 *
 * Por eso:
 *   - Si el usuario admin ya existe, no se toca nada (antes se le reescribía la
 *     contraseña con un valor fijo del repositorio en cada despliegue, lo que
 *     deshacía cualquier cambio de contraseña).
 *   - Los datos de demostración (PortalDemoSeeder) NO se llaman desde aquí: se
 *     ejecutan a mano cuando se quieren, y nunca en producción.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ADMIN_EMAIL = 'admin@olimedia.es';

    public function run(): void
    {
        if (User::where('email', self::ADMIN_EMAIL)->exists()) {
            return;
        }

        // Instalación nueva: contraseña aleatoria, que se imprime UNA vez para
        // poder entrar y cambiarla desde el panel. No se guarda en el repositorio.
        $password = Str::password(16);

        User::create([
            'name'     => 'OliMedia Admin',
            'email'    => self::ADMIN_EMAIL,
            'password' => Hash::make($password),
            'es_admin' => true,
        ]);

        $this->command?->warn('Usuario admin creado: ' . self::ADMIN_EMAIL);
        $this->command?->warn('Contraseña temporal: ' . $password);
        $this->command?->warn('Anótala y cámbiala al entrar en /admin. No se volverá a mostrar.');
    }
}

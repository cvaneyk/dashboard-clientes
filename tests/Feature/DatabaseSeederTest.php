<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PortalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El deploy ejecuta `db:seed --force` en cada push, así que este seeder corre
 * contra producción constantemente. Estos tests fijan que repetirlo es inofensivo.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_reescribe_la_contrasena_de_un_admin_que_ya_existe(): void
    {
        $original = Hash::make('la-que-puso-javier');

        User::create([
            'name'     => 'OliMedia Admin',
            'email'    => 'admin@olimedia.es',
            'password' => $original,
            'es_admin' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $original,
            User::where('email', 'admin@olimedia.es')->value('password'),
            'El seeder del deploy no puede tocar la contraseña de un admin existente'
        );
        $this->assertSame(1, User::where('email', 'admin@olimedia.es')->count());
    }

    public function test_crea_el_admin_con_una_contrasena_que_no_esta_en_el_repositorio(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@olimedia.es')->sole();

        $this->assertTrue($admin->es_admin);
        $this->assertNull($admin->cliente_id);
        $this->assertFalse(
            Hash::check('cambiar-esto', $admin->password),
            'La contraseña del admin no puede ser un valor fijo escrito en el código'
        );
    }

    public function test_no_carga_datos_de_demostracion(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Cliente::count(), 'El seeder del deploy no debe inventar clientes');
    }

    public function test_el_seeder_de_demostracion_se_niega_a_correr_en_produccion(): void
    {
        $this->app['env'] = 'production';

        // Se invoca directamente y no con seed(): en producción el comando db:seed
        // pediría confirmación por consola, que es otra capa distinta de esta guarda.
        $this->app->make(PortalDemoSeeder::class)->run();

        $this->assertSame(0, Cliente::count());
        $this->assertSame(0, User::count());
    }

    public function test_el_seeder_de_demostracion_si_carga_datos_fuera_de_produccion(): void
    {
        $this->seed(PortalDemoSeeder::class);

        $this->assertSame(1, Cliente::where('slug', 'la-salle')->count());
    }
}

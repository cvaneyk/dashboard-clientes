<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Usuario admin interno (accede al panel Filament en /admin).
        User::updateOrCreate(
            ['email' => 'admin@olimedia.es'],
            [
                'name'     => 'OliMedia Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('cambiar-esto'),
                'es_admin' => true,
            ]
        );

        // Datos piloto de La Salle (cliente + dashboard).
        $this->call(PortalDemoSeeder::class);
    }
}

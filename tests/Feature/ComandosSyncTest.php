<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConexionApi;
use App\Models\Cuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los comandos se usan por SSH en el servidor, donde no se ve el panel. Si no
 * encuentran la cuenta que les piden tienen que decir POR QUÉ y enseñar las que
 * hay con su ID: acertarlo a ciegas es lo que hace perder la tarde.
 */
class ComandosSyncTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(array $attrs = []): Cuenta
    {
        $cliente = Cliente::firstOrCreate(
            ['slug' => 'mi-universidad'],
            ['nombre' => 'Mi universidad', 'activo' => true]
        );

        return Cuenta::create(array_merge([
            'cliente_id'    => $cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'external_id'   => '123',
            'activo'        => true,
        ], $attrs));
    }

    private function conectar(Cuenta $cuenta): void
    {
        ConexionApi::create([
            'cuenta_id'    => $cuenta->id,
            'metodo'       => 'system_user',
            'access_token' => 'token-largo',
        ]);
    }

    public function test_sync_con_un_id_que_no_existe_lo_dice_y_lista_las_cuentas(): void
    {
        $cuenta = $this->cuenta();
        $this->conectar($cuenta);

        $this->artisan('portal:sync', ['--cuenta' => 999])
            ->expectsOutputToContain('no existe ninguna cuenta con ese ID')
            ->expectsOutputToContain('Mi universidad · Meta')
            ->assertFailed();
    }

    public function test_sync_avisa_si_la_plataforma_aun_no_tiene_conector(): void
    {
        $tiktok = $this->cuenta(['plataforma' => 'tiktok', 'nombre_cuenta' => 'Mi universidad · TikTok']);

        $this->artisan('portal:sync', ['--cuenta' => $tiktok->id])
            ->expectsOutputToContain('todavía no tiene conector')
            ->assertFailed();
    }

    public function test_sync_ya_tiene_en_cuenta_las_cuentas_de_google(): void
    {
        $google = $this->cuenta(['plataforma' => 'google', 'nombre_cuenta' => 'Mi universidad · Google']);

        // Sin conexión aún: el motivo debe ser ese, no "plataforma no soportada".
        $this->artisan('portal:sync', ['--cuenta' => $google->id])
            ->expectsOutputToContain('no tiene conexión con la API')
            ->assertFailed();
    }

    public function test_sync_avisa_si_la_cuenta_esta_inactiva(): void
    {
        $cuenta = $this->cuenta(['activo' => false]);
        $this->conectar($cuenta);

        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('inactiva')
            ->assertFailed();
    }

    public function test_sync_avisa_si_la_cuenta_no_tiene_conexion(): void
    {
        $cuenta = $this->cuenta();

        $this->artisan('portal:sync', ['--cuenta' => $cuenta->id])
            ->expectsOutputToContain('no tiene conexión con la API')
            ->assertFailed();
    }

    public function test_sync_sin_cuentas_conectadas_no_falla_para_no_alarmar_al_cron(): void
    {
        $this->artisan('portal:sync')
            ->expectsOutputToContain('No hay cuentas conectadas')
            ->assertSuccessful();
    }

    public function test_meta_acciones_con_id_erroneo_lista_las_cuentas_con_su_estado(): void
    {
        $cuenta = $this->cuenta();
        $this->conectar($cuenta);

        $this->artisan('portal:meta-acciones', ['cuenta' => 999])
            ->expectsOutputToContain('no existe ninguna cuenta con ese ID')
            ->expectsOutputToContain('conectada')
            ->assertFailed();
    }
}

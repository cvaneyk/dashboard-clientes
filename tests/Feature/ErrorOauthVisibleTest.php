<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cuando una plataforma rechaza la autorización, el motivo viaja en
 * ?error_description= y es el único sitio donde dice qué pasa de verdad.
 *
 * Se perdía: el callback lo sustituía por "Conexión cancelada", que además de
 * tapar la información era una suposición falsa —casi nunca cancela el usuario,
 * rechaza la plataforma— y dejaba al admin sin nada que investigar.
 */
class ErrorOauthVisibleTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(string $plataforma): Cuenta
    {
        $cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        return Cuenta::create([
            'cliente_id'    => $cliente->id,
            'plataforma'    => $plataforma,
            'nombre_cuenta' => 'Mi universidad',
            'external_id'   => '512345',
            'activo'        => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

    public function test_el_motivo_de_linkedin_llega_al_admin(): void
    {
        $cuenta = $this->cuenta('linkedin');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.linkedin' => ['nonce' => 'n', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/linkedin/callback?state=n&error=unauthorized_scope_error'
                . '&error_description=Scope+%22r_ads%22+is+not+authorized+for+your+application');

        $destino = urldecode((string) $respuesta->headers->get('Location'));

        $this->assertStringContainsString('is not authorized for your application', $destino);
        $this->assertStringNotContainsString('cancelada', $destino);
    }

    public function test_una_cancelacion_de_verdad_se_dice_como_tal(): void
    {
        $cuenta = $this->cuenta('linkedin');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.linkedin' => ['nonce' => 'n', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/linkedin/callback?state=n&error=user_cancelled_login');

        $this->assertStringContainsString(
            'cancelada',
            urldecode((string) $respuesta->headers->get('Location'))
        );
    }

    public function test_sin_descripcion_al_menos_llega_el_codigo_de_error(): void
    {
        $cuenta = $this->cuenta('google');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.google' => ['nonce' => 'n', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/google/callback?state=n&error=access_denied_by_policy');

        $this->assertStringContainsString(
            'access_denied_by_policy',
            urldecode((string) $respuesta->headers->get('Location'))
        );
    }

    public function test_meta_tambien_explica_el_motivo(): void
    {
        $cuenta = $this->cuenta('meta');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withSession(['conexiones.meta' => ['nonce' => 'n', 'cuenta_id' => $cuenta->id]])
            ->get('/conexiones/meta/callback?state=n&error=access_denied'
                . '&error_description=Permissions+error');

        $this->assertStringContainsString(
            'Permissions error',
            urldecode((string) $respuesta->headers->get('Location'))
        );
    }
}

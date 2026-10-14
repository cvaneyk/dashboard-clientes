<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El redirect_uri de OAuth tiene que salir en https.
 *
 * En Plesk el SSL termina en el proxy y a PHP le llega la petición como HTTP
 * plano. Sin confiar en las cabeceras del proxy, Laravel construye las URLs
 * absolutas con http:// y las plataformas rechazan el redirect_uri, que
 * comparan carácter por carácter ("The redirect_uri does not match the
 * registered value").
 *
 * No se vio antes porque Meta se conectó con token de System User y Google con
 * el refresh token de la MCC: LinkedIn es el primer flujo que usa redirección.
 */
class RedirectUriHttpsTest extends TestCase
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

    public function test_linkedin_manda_un_redirect_uri_https_tras_el_proxy(): void
    {
        config([
            'services.linkedin_ads.client_id'     => 'cliente',
            'services.linkedin_ads.client_secret' => 'secreto',
        ]);

        $cuenta = $this->cuenta('linkedin');

        // Así llega la petición desde el proxy de Plesk: HTTP por dentro,
        // con la cabecera diciendo que por fuera era HTTPS.
        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/conexiones/linkedin/autorizar?cuenta=' . $cuenta->id);

        $destino = urldecode((string) $respuesta->headers->get('Location'));

        $this->assertStringContainsString('linkedin.com/oauth/v2/authorization', $destino);
        $this->assertStringContainsString('redirect_uri=https://', $destino, 'El redirect_uri no puede salir en http tras el proxy');
        $this->assertStringContainsString('/conexiones/linkedin/callback', $destino);
    }

    public function test_google_manda_un_redirect_uri_https_tras_el_proxy(): void
    {
        config([
            'services.google_ads.client_id'       => 'cliente',
            'services.google_ads.client_secret'   => 'secreto',
            'services.google_ads.developer_token' => 'DEV',
        ]);

        $cuenta = $this->cuenta('google');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/conexiones/google/autorizar?cuenta=' . $cuenta->id);

        $this->assertStringContainsString(
            'redirect_uri=https://',
            urldecode((string) $respuesta->headers->get('Location'))
        );
    }

    public function test_meta_manda_un_redirect_uri_https_tras_el_proxy(): void
    {
        config(['services.meta.app_id' => 'app', 'services.meta.app_secret' => 'secreto']);

        $cuenta = $this->cuenta('meta');

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/conexiones/meta/autorizar?cuenta=' . $cuenta->id);

        $this->assertStringContainsString(
            'redirect_uri=https://',
            urldecode((string) $respuesta->headers->get('Location'))
        );
    }
}

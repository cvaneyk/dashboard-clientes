<?php

namespace App\Http\Controllers\Conexiones;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Http\Controllers\Controller;
use App\Models\Cuenta;
use App\Services\Sync\SpotifyConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flujo OAuth de Spotify Ads, calcado del de LinkedIn: ruta de callback fija y
 * la cuenta a conectar viaja por sesión dentro del `state`, que hace de nonce
 * anti-CSRF.
 *
 * Quien autoriza es un usuario de Spotify con acceso a la cuenta publicitaria
 * del cliente en Ads Manager.
 */
class SpotifyConexionController extends Controller
{
    use ExplicaErrorDelProveedor;

    public function autorizar(Request $request, SpotifyConnector $connector): RedirectResponse
    {
        $cuenta = Cuenta::findOrFail($request->query('cuenta'));

        if ($cuenta->plataforma !== 'spotify') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Esa cuenta no es de Spotify Ads.');
        }
        if (! $connector->appConfigurada()) {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Falta configurar Spotify en el .env del servidor: SPOTIFY_ADS_CLIENT_ID y SPOTIFY_ADS_CLIENT_SECRET.');
        }
        if (trim((string) $cuenta->external_id) === '') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Antes de conectar, rellena el "ID de cuenta en la plataforma" (el UUID de la cuenta publicitaria en Ads Manager).');
        }

        $nonce = Str::random(40);
        $request->session()->put('conexiones.spotify', [
            'nonce'     => $nonce,
            'cuenta_id' => $cuenta->id,
        ]);

        return redirect()->away($connector->urlAutorizacion($nonce, route('conexiones.spotify.callback')));
    }

    public function callback(Request $request, SpotifyConnector $connector): RedirectResponse
    {
        $estado = (array) $request->session()->pull('conexiones.spotify', []);

        if ($error = $request->query('error')) {
            return $this->volver($estado, 'error', $this->motivo($request, 'Spotify', $error));
        }

        $nonceRecibido = (string) $request->query('state', '');
        if (($estado['nonce'] ?? '') === '' || ! hash_equals($estado['nonce'], $nonceRecibido)) {
            return $this->volver($estado, 'error', 'La sesión de conexión caducó o no coincide. Vuelve a lanzarla desde la ficha de la cuenta.');
        }

        /** @var Cuenta|null $cuenta */
        $cuenta = Cuenta::find($estado['cuenta_id'] ?? null);

        if (! $cuenta || $cuenta->plataforma !== 'spotify') {
            return $this->volver($estado, 'error', 'La cuenta a conectar ya no existe.');
        }

        try {
            $connector->guardarTokenOauth($cuenta, (string) $request->query('code', ''), route('conexiones.spotify.callback'));
        } catch (Throwable $e) {
            // Una conexión que no lee la cuenta no vale: no se deja a medias.
            $cuenta->conexionApi()->delete();

            return $this->volver($estado, 'error', $e->getMessage());
        }

        return $this->volver(['cuenta_id' => $cuenta->id], 'ok');
    }

    /** @param  array<string,mixed>  $estado */
    private function volver(array $estado, string $resultado, ?string $mensaje = null): RedirectResponse
    {
        $cuenta = Cuenta::find($estado['cuenta_id'] ?? null);

        $url = $cuenta
            ? CuentaResource::getUrl('edit', ['record' => $cuenta])
            : CuentaResource::getUrl('index');

        $params = array_filter(['conexion' => $resultado, 'mensaje' => $mensaje]);

        return redirect()->to($url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params));
    }
}

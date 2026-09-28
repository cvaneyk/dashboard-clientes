<?php

namespace App\Http\Controllers\Conexiones;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Http\Controllers\Controller;
use App\Models\Cuenta;
use App\Services\Sync\MetaConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flujo OAuth de Meta (Facebook Login) para conectar una Cuenta publicitaria
 * con la API del propio cliente.
 *
 * Rutas fijas, sin el id de la cuenta en la URL: en la app de Facebook solo se
 * registra UN redirect URI ({APP_URL}/conexiones/meta/callback) y la cuenta a
 * la que pertenece la conexión viaja por sesión dentro del parámetro `state`,
 * que además actúa de anti-CSRF (nonce de un solo uso).
 */
class MetaConexionController extends Controller
{
    use ExplicaErrorDelProveedor;

    /** Paso 1: manda al admin (o al cliente) al diálogo de Facebook Login. */
    public function autorizar(Request $request, MetaConnector $connector): RedirectResponse
    {
        $cuenta = Cuenta::findOrFail($request->query('cuenta'));

        // Los problemas vuelven a la ficha como notificación, no como página de error.
        if ($cuenta->plataforma !== 'meta') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Esa cuenta no es de Meta.');
        }
        if (! $connector->appConfigurada()) {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Falta configurar la app de Meta: META_APP_ID y META_APP_SECRET en el .env del servidor.');
        }
        if (trim((string) $cuenta->external_id) === '') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Antes de conectar, rellena el "ID de cuenta en la plataforma" en la ficha de la Cuenta.');
        }

        $nonce = Str::random(40);
        $request->session()->put('conexiones.meta', [
            'nonce'     => $nonce,
            'cuenta_id' => $cuenta->id,
        ]);

        return redirect()->away($connector->urlAutorizacion($nonce, route('conexiones.meta.callback')));
    }

    /** Paso 2: Facebook vuelve aquí con el `code`; se canjea y se guarda la conexión. */
    public function callback(Request $request, MetaConnector $connector): RedirectResponse
    {
        $estado = (array) $request->session()->pull('conexiones.meta', []);

        // El usuario canceló el diálogo, o Facebook rechazó algo y lo explica
        // en error_description.
        if ($error = $request->query('error')) {
            return $this->volver($estado, 'error', $this->motivo($request, 'Meta', $error));
        }

        $nonceRecibido = (string) $request->query('state', '');
        if (($estado['nonce'] ?? '') === '' || ! hash_equals($estado['nonce'], $nonceRecibido)) {
            return $this->volver($estado, 'error', 'La sesión de conexión caducó o no coincide. Vuelve a lanzar la conexión desde la ficha de la cuenta.');
        }

        /** @var Cuenta|null $cuenta */
        $cuenta = Cuenta::find($estado['cuenta_id'] ?? null);

        if (! $cuenta || $cuenta->plataforma !== 'meta') {
            return $this->volver($estado, 'error', 'La cuenta a conectar ya no existe.');
        }

        try {
            $connector->guardarTokenOauth($cuenta, (string) $request->query('code', ''), route('conexiones.meta.callback'));
        } catch (Throwable $e) {
            return $this->volver($estado, 'error', $e->getMessage());
        }

        return $this->volver(['cuenta_id' => $cuenta->id], 'ok');
    }

    /**
     * Vuelve a la ficha de la Cuenta con el resultado en la query; la página
     * de edición lo convierte en notificación de Filament.
     *
     * @param  array<string,mixed>  $estado
     */
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

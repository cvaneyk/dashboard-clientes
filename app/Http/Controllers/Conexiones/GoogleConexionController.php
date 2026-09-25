<?php

namespace App\Http\Controllers\Conexiones;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Http\Controllers\Controller;
use App\Models\Cuenta;
use App\Services\Sync\GoogleAdsConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flujo OAuth de Google Ads, calcado del de Meta: rutas fijas (en Google Cloud
 * solo se registra UN URI de redirección) y la cuenta a conectar viaja por
 * sesión dentro del `state`, que hace de nonce anti-CSRF.
 *
 * Se usa cuando la cuenta la gestiona el propio cliente con su email. Para las
 * cuentas que cuelgan de la MCC de OliMedia basta con pegar el refresh token
 * una vez desde la ficha de la Cuenta.
 */
class GoogleConexionController extends Controller
{
    public function autorizar(Request $request, GoogleAdsConnector $connector): RedirectResponse
    {
        $cuenta = Cuenta::findOrFail($request->query('cuenta'));

        if ($cuenta->plataforma !== 'google') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Esa cuenta no es de Google Ads.');
        }
        if (! $connector->appConfigurada()) {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Falta configurar Google en el .env del servidor: GOOGLE_ADS_CLIENT_ID, GOOGLE_ADS_CLIENT_SECRET y GOOGLE_ADS_DEVELOPER_TOKEN.');
        }
        if (trim((string) $cuenta->external_id) === '') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Antes de conectar, rellena el "ID de cuenta en la plataforma" (el customer ID de 10 cifras).');
        }

        $nonce = Str::random(40);
        $request->session()->put('conexiones.google', [
            'nonce'     => $nonce,
            'cuenta_id' => $cuenta->id,
        ]);

        return redirect()->away($connector->urlAutorizacion($nonce, route('conexiones.google.callback')));
    }

    public function callback(Request $request, GoogleAdsConnector $connector): RedirectResponse
    {
        $estado = (array) $request->session()->pull('conexiones.google', []);

        if ($request->query('error')) {
            return $this->volver($estado, 'error', 'Conexión cancelada desde Google.');
        }

        $nonceRecibido = (string) $request->query('state', '');
        if (($estado['nonce'] ?? '') === '' || ! hash_equals($estado['nonce'], $nonceRecibido)) {
            return $this->volver($estado, 'error', 'La sesión de conexión caducó o no coincide. Vuelve a lanzarla desde la ficha de la cuenta.');
        }

        /** @var Cuenta|null $cuenta */
        $cuenta = Cuenta::find($estado['cuenta_id'] ?? null);

        if (! $cuenta || $cuenta->plataforma !== 'google') {
            return $this->volver($estado, 'error', 'La cuenta a conectar ya no existe.');
        }

        try {
            $connector->guardarTokenOauth($cuenta, (string) $request->query('code', ''), route('conexiones.google.callback'));
        } catch (Throwable $e) {
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

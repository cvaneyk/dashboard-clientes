<?php

namespace App\Http\Controllers\Conexiones;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Http\Controllers\Controller;
use App\Models\Cuenta;
use App\Services\Sync\LinkedInConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flujo OAuth de LinkedIn Ads, calcado del de Meta y Google: ruta de callback
 * fija y la cuenta a conectar viaja por sesión dentro del `state`, que hace de
 * nonce anti-CSRF.
 *
 * En LinkedIn no hay token de agencia: el acceso a la cuenta del cliente se
 * concede por Business Manager y luego alguien con ese acceso autoriza aquí.
 */
class LinkedInConexionController extends Controller
{
    public function autorizar(Request $request, LinkedInConnector $connector): RedirectResponse
    {
        $cuenta = Cuenta::findOrFail($request->query('cuenta'));

        if ($cuenta->plataforma !== 'linkedin') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Esa cuenta no es de LinkedIn Ads.');
        }
        if (! $connector->appConfigurada()) {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Falta configurar LinkedIn en el .env del servidor: LINKEDIN_ADS_CLIENT_ID y LINKEDIN_ADS_CLIENT_SECRET.');
        }
        if (trim((string) $cuenta->external_id) === '') {
            return $this->volver(['cuenta_id' => $cuenta->id], 'error', 'Antes de conectar, rellena el "ID de cuenta en la plataforma" (el número de la cuenta publicitaria).');
        }

        $nonce = Str::random(40);
        $request->session()->put('conexiones.linkedin', [
            'nonce'     => $nonce,
            'cuenta_id' => $cuenta->id,
        ]);

        return redirect()->away($connector->urlAutorizacion($nonce, route('conexiones.linkedin.callback')));
    }

    public function callback(Request $request, LinkedInConnector $connector): RedirectResponse
    {
        $estado = (array) $request->session()->pull('conexiones.linkedin', []);

        if ($request->query('error')) {
            return $this->volver($estado, 'error', 'Conexión cancelada desde LinkedIn.');
        }

        $nonceRecibido = (string) $request->query('state', '');
        if (($estado['nonce'] ?? '') === '' || ! hash_equals($estado['nonce'], $nonceRecibido)) {
            return $this->volver($estado, 'error', 'La sesión de conexión caducó o no coincide. Vuelve a lanzarla desde la ficha de la cuenta.');
        }

        /** @var Cuenta|null $cuenta */
        $cuenta = Cuenta::find($estado['cuenta_id'] ?? null);

        if (! $cuenta || $cuenta->plataforma !== 'linkedin') {
            return $this->volver($estado, 'error', 'La cuenta a conectar ya no existe.');
        }

        try {
            $connector->guardarTokenOauth($cuenta, (string) $request->query('code', ''), route('conexiones.linkedin.callback'));
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

<?php

namespace App\Filament\Resources\Cuentas\Pages;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Models\ConexionApi;
use App\Services\Sync\Conectores;
use App\Services\Sync\GoogleAdsConnector;
use App\Services\Sync\OpenAiAdsConnector;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditCuenta extends EditRecord
{
    protected static string $resource = CuentaResource::class;

    /**
     * La conexión OAuth de Meta vuelve aquí con ?conexion=ok|error y el
     * detalle en ?mensaje; se convierte en notificación para el admin.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (request()->query('conexion') === 'ok') {
            Notification::make()
                ->title('Cuenta conectada con la API de ' . Conectores::nombre($this->getRecord()->plataforma))
                ->success()
                ->send();
        }
        if (request()->query('conexion') === 'error') {
            Notification::make()
                ->title('No se pudo conectar la cuenta')
                ->body(request()->query('mensaje'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $soloMeta     = fn () => $this->getRecord()->plataforma === 'meta';
        $soloGoogle   = fn () => $this->getRecord()->plataforma === 'google';
        $soloLinkedin = fn () => $this->getRecord()->plataforma === 'linkedin';
        $soloSpotify  = fn () => $this->getRecord()->plataforma === 'spotify';
        $soloOpenai   = fn () => $this->getRecord()->plataforma === 'openai';
        $conApi     = fn () => Conectores::soportada($this->getRecord()->plataforma);

        return [
            DeleteAction::make(),

            // Estado de la conexión API (hoy, Meta). Abre un desglose en modal.
            Action::make('estadoConexion')
                ->label(fn () => $this->etiquetaEstado())
                ->icon(fn () => $this->getRecord()->conexionApi?->ultimo_error
                    ? 'heroicon-o-exclamation-triangle'
                    : 'heroicon-o-bolt')
                ->color(fn () => $this->getRecord()->conexionApi
                    ? ($this->getRecord()->conexionApi->ultimo_error ? 'danger' : 'success')
                    : 'gray')
                ->modalHeading(fn () => 'Conexión con la API de ' . Conectores::nombre($this->getRecord()->plataforma))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->schema(fn () => $this->detallesEstado())
                ->visible($conApi),

            // Método A: el cliente conecta su propia cuenta (Facebook Login).
            Action::make('conectarOauth')
                ->label(fn () => $this->getRecord()->conexionApi?->metodo === 'oauth'
                    ? 'Reconectar (OAuth del cliente)'
                    : 'Conectar con OAuth del cliente')
                ->icon('heroicon-o-link')
                ->url(fn () => route('conexiones.meta.autorizar', ['cuenta' => $this->getRecord()->id]))
                ->visible($soloMeta),

            // Método B: token de System User de OliMedia, pegado a mano.
            Action::make('conectarToken')
                ->label(fn () => ($this->getRecord()->conexionApi?->metodo === 'system_user')
                    ? 'Renovar token de System User'
                    : 'Conectar con token de System User')
                ->icon('heroicon-o-key')
                ->modalHeading('Token de System User de Meta')
                ->modalDescription('Pega el token de System User del Business Manager con permiso ads_read. No caduca: válido para las cuentas que gestiona OliMedia.')
                ->modalSubmitActionLabel('Guardar token')
                ->schema([
                    TextInput::make('token')
                        ->label('Token')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    ConexionApi::updateOrCreate(
                        ['cuenta_id' => $this->getRecord()->id],
                        [
                            'metodo'       => 'system_user',
                            'access_token' => $data['token'],
                            'expires_at'   => null,
                            'ultimo_error' => null,
                        ]
                    );

                    Notification::make()->title('Token guardado; la cuenta queda conectada')->success()->send();
                })
                ->visible($soloMeta),

            // Google · método A: el cliente autoriza su propia cuenta.
            Action::make('conectarGoogleOauth')
                ->label(fn () => $this->getRecord()->conexionApi?->metodo === 'oauth'
                    ? 'Reconectar (cuenta del cliente)'
                    : 'Conectar con la cuenta del cliente')
                ->icon('heroicon-o-link')
                ->url(fn () => route('conexiones.google.autorizar', ['cuenta' => $this->getRecord()->id]))
                ->visible($soloGoogle),

            // Google · método B: refresh token de la MCC de OliMedia. Uno solo
            // vale para todas las cuentas que cuelgan de ella.
            Action::make('conectarGoogleMcc')
                ->label(fn () => ($this->getRecord()->conexionApi?->metodo === 'mcc')
                    ? 'Renovar refresh token de la MCC'
                    : 'Conectar con la MCC de OliMedia')
                ->icon('heroicon-o-key')
                ->modalHeading('Refresh token de Google Ads')
                ->modalDescription('Para las cuentas que cuelgan de vuestra cuenta de administrador (MCC). El mismo refresh token sirve para todos esos clientes: lo que cambia es el customer ID de cada Cuenta.')
                ->modalSubmitActionLabel('Guardar y comprobar')
                ->schema([
                    TextInput::make('refresh_token')
                        ->label('Refresh token')
                        ->password()
                        ->revealable()
                        ->required(),
                    TextInput::make('login_customer_id')
                        ->label('ID de la MCC (opcional)')
                        ->helperText('Solo si es distinto del configurado en el servidor. Con o sin guiones.'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(GoogleAdsConnector::class)->guardarRefreshToken(
                            $this->getRecord(),
                            $data['refresh_token'],
                            $data['login_customer_id'] ?? null
                        );

                        Notification::make()->title('Cuenta conectada con Google Ads')->success()->send();
                    } catch (Throwable $e) {
                        // Si el token no lee nada, la conexión no vale: se borra
                        // para no dejar una cuenta "conectada" que falla de noche.
                        $this->getRecord()->conexionApi()->delete();

                        Notification::make()
                            ->title('No se pudo conectar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                })
                ->visible($soloGoogle),

            // LinkedIn no tiene token de agencia: el acceso se concede por
            // Business Manager y luego alguien con ese acceso autoriza aquí.
            Action::make('conectarLinkedin')
                ->label(fn () => $this->getRecord()->conexionApi
                    ? 'Reconectar con LinkedIn'
                    : 'Conectar con LinkedIn')
                ->icon('heroicon-o-link')
                ->url(fn () => route('conexiones.linkedin.autorizar', ['cuenta' => $this->getRecord()->id]))
                ->visible($soloLinkedin),

            // Spotify: un usuario con acceso a la cuenta en Ads Manager autoriza.
            Action::make('conectarSpotify')
                ->label(fn () => $this->getRecord()->conexionApi
                    ? 'Reconectar con Spotify'
                    : 'Conectar con Spotify')
                ->icon('heroicon-o-link')
                ->url(fn () => route('conexiones.spotify.autorizar', ['cuenta' => $this->getRecord()->id]))
                ->visible($soloSpotify),

            // OpenAI: cada cuenta publicitaria tiene su propia clave de API.
            Action::make('conectarOpenai')
                ->label(fn () => $this->getRecord()->conexionApi
                    ? 'Cambiar clave de API de OpenAI'
                    : 'Conectar con clave de API de OpenAI')
                ->icon('heroicon-o-key')
                ->modalHeading('Clave de API de OpenAI Ads')
                ->modalDescription('Se genera en Ads Manager de OpenAI → Settings, y cada clave solo da acceso a una cuenta publicitaria: usa la de este cliente. Se comprueba al guardar.')
                ->modalSubmitActionLabel('Guardar y comprobar')
                ->schema([
                    TextInput::make('api_key')
                        ->label('Clave de API')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(OpenAiAdsConnector::class)->guardarApiKey($this->getRecord(), $data['api_key']);

                        Notification::make()->title('Cuenta conectada con OpenAI Ads')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('No se pudo conectar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                })
                ->visible($soloOpenai),

            Action::make('sincronizarAhora')
                ->label('Sincronizar ahora')
                ->icon('heroicon-o-arrow-path')
                ->modalHeading(fn () => 'Sincronizar datos de ' . Conectores::nombre($this->getRecord()->plataforma))
                ->modalDescription('Sin fechas trae los últimos 3 días. Indica un rango para recuperar histórico o rehacer un periodo: volver a bajar días ya importados los sobrescribe, no los duplica.')
                ->modalSubmitActionLabel('Sincronizar')
                ->schema([
                    DatePicker::make('desde')
                        ->label('Desde (opcional)')
                        ->maxDate(now()),
                    DatePicker::make('hasta')
                        ->label('Hasta (opcional)')
                        ->maxDate(now())
                        ->helperText('Vacío = hoy. Rangos largos tardan y pueden topar con el límite de peticiones de Meta.'),
                ])
                ->action(fn (array $data) => $this->sincronizar($data['desde'] ?? null, $data['hasta'] ?? null))
                ->visible(fn () => $conApi() && $this->getRecord()->conexionApi()->exists()),

            Action::make('desconectarApi')
                ->label('Desconectar API')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Se borra el token de la conexión. Los datos ya importados se conservan; la cuenta vuelve a alimentarse con informes subidos a mano.')
                ->action(function (): void {
                    $this->getRecord()->conexionApi()->delete();

                    Notification::make()->title('Conexión eliminada')->success()->send();
                })
                ->visible(fn () => $conApi() && $this->getRecord()->conexionApi()->exists()),
        ];
    }

    private function sincronizar(?string $desde = null, ?string $hasta = null): void
    {
        try {
            $conector = Conectores::para($this->getRecord());

            // Si se indica solo una de las dos fechas, la otra se completa:
            // "desde" suelto llega hasta hoy; "hasta" suelto arranca ese mismo día.
            $res = ($desde || $hasta)
                ? $conector->sincronizarRango(
                    $this->getRecord(),
                    $desde ?? $hasta,
                    $hasta ?? now()->toDateString()
                )
                : $conector->sincronizar($this->getRecord());

            Notification::make()
                ->title('Sincronización completada')
                ->body(sprintf(
                    '%d filas · %d líneas nuevas · %s € · %s → %s%s',
                    $res['filas'],
                    $res['lineas_nuevas'],
                    number_format($res['gasto_real'], 2, ',', '.'),
                    $res['periodo_inicio'] ?? '?',
                    $res['periodo_fin'] ?? '?',
                    $res['filas_error'] > 0 ? sprintf(' · %d filas descartadas', $res['filas_error']) : ''
                ))
                ->success()
                ->persistent()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Error al sincronizar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function etiquetaEstado(): string
    {
        $conexion = $this->getRecord()->conexionApi;

        if (! $conexion) {
            return 'API: sin conectar';
        }

        $plataforma = Conectores::nombre($this->getRecord()->plataforma);
        $metodo     = $this->nombreMetodo($conexion->metodo);

        return $conexion->ultimo_error
            ? "API {$plataforma} ({$metodo}): con error"
            : "API {$plataforma} ({$metodo}): conectada";
    }

    private function nombreMetodo(?string $metodo): string
    {
        return match ($metodo) {
            'oauth'       => 'cuenta del cliente',
            'system_user' => 'System User',
            'mcc'         => 'MCC',
            'api_key'     => 'clave de API',
            default       => (string) $metodo,
        };
    }

    /** @return array<int,Placeholder> */
    private function detallesEstado(): array
    {
        $conexion = $this->getRecord()->conexionApi;

        if (! $conexion) {
            return [
                Placeholder::make('ayuda')->content(
                    match ($this->getRecord()->plataforma) {
                        'google' => 'Conecta esta cuenta con la MCC de OliMedia (un refresh token vale para todos los '
                            . 'clientes que cuelgan de ella) o con la cuenta del propio cliente. Después, la '
                            . 'sincronización diaria trae los datos sola.',
                        'linkedin' => 'Pide antes acceso a la cuenta del cliente en LinkedIn Business Manager; luego '
                            . 'conecta aquí con un usuario que tenga ese acceso. Requiere que vuestra app esté '
                            . 'aprobada en el Marketing Developer Platform.',
                        'spotify' => 'Rellena el ID de la cuenta publicitaria (el UUID de Ads Manager) y conecta con un '
                            . 'usuario de Spotify que tenga acceso a ella. La app tiene que haber aceptado los '
                            . 'términos de la Ads API.',
                        'openai' => 'Pega la clave de API de la cuenta publicitaria de este cliente (Ads Manager de '
                            . 'OpenAI → Settings). Cada clave solo ve su cuenta.',
                        default => 'Conecta esta cuenta con "OAuth del cliente" (cada cliente con su propio acceso a '
                            . 'la API) o con "token de System User" (cuentas gestionadas por OliMedia). Después, la '
                            . 'sincronización diaria trae los datos sola.',
                    }
                ),
            ];
        }

        return [
            Placeholder::make('metodo')
                ->label('Método')
                ->content(match ($conexion->metodo) {
                    'oauth'       => 'Acceso del propio cliente; el token se renueva solo',
                    'system_user' => 'Token de System User de OliMedia (no caduca)',
                    'mcc'         => 'Refresh token de la MCC de OliMedia (no caduca)',
                    'api_key'     => 'Clave de API de la cuenta publicitaria (no caduca)',
                    default       => (string) $conexion->metodo,
                }),
            Placeholder::make('caducidad')
                // En Google y Spotify el access_token dura una hora y se renueva
                // solo con el refresh token: esa fecha no es la vida de la conexión.
                ->label(in_array($this->getRecord()->plataforma, ['google', 'spotify'], true) ? 'Access token válido hasta' : 'El token caduca')
                ->content($conexion->expires_at?->format('d/m/Y H:i') ?? 'Nunca'),
            Placeholder::make('ultima')
                ->label('Última sincronización')
                ->content($conexion->ultima_sincronizacion?->format('d/m/Y H:i') ?? 'Aún no'),
            Placeholder::make('error')
                ->label('Último error')
                ->content($conexion->ultimo_error ?? '—'),
        ];
    }
}

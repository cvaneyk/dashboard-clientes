<?php

namespace App\Filament\Resources\Cuentas\Pages;

use App\Filament\Resources\Cuentas\CuentaResource;
use App\Models\ConexionApi;
use App\Services\Sync\MetaConnector;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
            Notification::make()->title('Cuenta conectada con la API de Meta')->success()->send();
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
        $soloMeta = fn () => $this->getRecord()->plataforma === 'meta';

        return [
            DeleteAction::make(),

            // Estado de la conexión API (hoy, Meta). Abre un desglose en modal.
            Action::make('estadoConexion')
                ->label(fn () => $this->etiquetaEstado())
                ->icon(fn () => $this->getRecord()->conexionApi?->ultimo_error
                    ? 'heroicon-o-exclamation-triangle'
                    : 'heroicon-o-plug')
                ->color(fn () => $this->getRecord()->conexionApi
                    ? ($this->getRecord()->conexionApi->ultimo_error ? 'danger' : 'success')
                    : 'gray')
                ->modalHeading('Conexión con la API de Meta')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->schema(fn () => $this->detallesEstado())
                ->visible($soloMeta),

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

            Action::make('sincronizarAhora')
                ->label('Sincronizar ahora')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->sincronizar())
                ->visible(fn () => $soloMeta() && $this->getRecord()->conexionApi()->exists()),

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
                ->visible(fn () => $soloMeta() && $this->getRecord()->conexionApi()->exists()),
        ];
    }

    private function sincronizar(): void
    {
        try {
            $res = app(MetaConnector::class)->sincronizar($this->getRecord());

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

        $metodo = $conexion->metodo === 'oauth' ? 'OAuth' : 'System User';

        return $conexion->ultimo_error
            ? "API Meta ({$metodo}): con error"
            : "API Meta ({$metodo}): conectada";
    }

    /** @return array<int,Placeholder> */
    private function detallesEstado(): array
    {
        $conexion = $this->getRecord()->conexionApi;

        if (! $conexion) {
            return [
                Placeholder::make('ayuda')->content(
                    'Conecta esta cuenta con "OAuth del cliente" (cada cliente con su propio acceso a la API) '
                    . 'o con "token de System User" (cuentas gestionadas por OliMedia). Después, la sincronización '
                    . 'diaria trae los datos sola.'
                ),
            ];
        }

        return [
            Placeholder::make('metodo')
                ->label('Método')
                ->content($conexion->metodo === 'oauth'
                    ? 'OAuth del cliente (Facebook Login), token de vida larga que se renueva solo'
                    : 'Token de System User (no caduca)'),
            Placeholder::make('caducidad')
                ->label('El token caduca')
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

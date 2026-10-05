<?php

namespace App\Filament\Resources\PlantillasEmail\Pages;

use App\Filament\Resources\PlantillasEmail\PlantillaEmailResource;
use App\Mail\InformeMail;
use App\Models\Cliente;
use App\Models\PlantillaEmail;
use App\Services\Informes\InformesSemanales;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EditPlantillaEmail extends EditRecord
{
    protected static string $resource = PlantillaEmailResource::class;

    protected function getHeaderActions(): array
    {
        $esCliente = fn () => $this->getRecord()->clave === PlantillaEmail::INFORME_SEMANAL;

        return [
            // Prueba con datos reales de la semana pasada. Usa la versión
            // GUARDADA de la plantilla: primero se guarda y luego se prueba.
            Action::make('enviarPrueba')
                ->label('Enviar prueba')
                ->icon('heroicon-o-paper-airplane')
                ->modalDescription(fn () => 'Se envía la versión guardada, con los datos de la semana del '
                    . InformesSemanales::periodo(...InformesSemanales::semanaAnterior()) . '.'
                    . (InformeMail::smtpConfigurado() ? '' : ' ATENCIÓN: el SMTP no está configurado (MAIL_MAILER='
                        . config('mail.default') . '), el correo no saldrá.'))
                ->schema(fn () => array_values(array_filter([
                    $esCliente()
                        ? Select::make('cliente_id')
                            ->label('Con los datos de')
                            ->options(Cliente::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'))
                            ->required()
                        : null,
                    TextInput::make('para')
                        ->label('Enviar a')
                        ->email()
                        ->required()
                        ->default(Auth::user()?->email),
                ])))
                ->modalSubmitActionLabel('Enviar')
                ->action(function (array $data, InformesSemanales $informes) use ($esCliente) {
                    try {
                        $semana = InformesSemanales::semanaAnterior();

                        if ($esCliente()) {
                            $cliente = Cliente::findOrFail($data['cliente_id']);
                            $informe = $informes->paraCliente($cliente, ...$semana);
                            $mail    = new InformeMail($informe['asunto'], $informe['cuerpo'], $cliente->color);
                        } else {
                            $informe = $informes->paraAdmin(...$semana);
                            $mail    = new InformeMail($informe['asunto'], $informe['cuerpo']);
                        }

                        Mail::to($data['para'])->send($mail);

                        Notification::make()->title('Prueba enviada a ' . $data['para'])->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()
                            ->title('No se pudo enviar la prueba')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}

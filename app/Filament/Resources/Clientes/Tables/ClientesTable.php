<?php

namespace App\Filament\Resources\Clientes\Tables;

use App\Mail\InformeMail;
use App\Models\Cliente;
use App\Services\Informes\InformesSemanales;
use App\Support\AgenciaActual;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('agencia.nombre')
                    ->label('Agencia')
                    ->sortable()
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                TextColumn::make('lineas_count')
                    ->label('Líneas')
                    ->counts('lineas')
                    ->badge(),
                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
                IconColumn::make('informe_semanal')
                    ->label('Informe semanal')
                    ->boolean()
                    ->tooltip(fn (Cliente $record) => $record->email_informes ?: 'Sin email'),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->recordActions([
                EditAction::make(),
                // Abre el dashboard tal y como lo ve ese cliente. Útil para
                // revisar antes de una reunión sin pedirle sus credenciales.
                Action::make('verDashboard')
                    ->label('Ver dashboard')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color('gray')
                    ->url(fn (Cliente $record) => route('portal.dashboard', ['cliente' => $record->slug]))
                    ->openUrlInNewTab()
                    ->visible(fn (Cliente $record) => $record->activo),
                // Envía ya el informe de la semana pasada. Sirve para probar la
                // plantilla y el SMTP, o para reenviarlo si el cliente lo pide.
                Action::make('enviarInforme')
                    ->label('Enviar informe')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->modalHeading(fn (Cliente $record) => 'Enviar informe semanal · ' . $record->nombre)
                    ->modalDescription(fn () => 'Semana del ' . InformesSemanales::periodo(...InformesSemanales::semanaAnterior())
                        . (InformeMail::smtpConfigurado() ? '' : '. ATENCIÓN: el SMTP no está configurado (MAIL_MAILER=' . config('mail.default') . '), el correo no saldrá.'))
                    ->schema([
                        TextInput::make('para')
                            ->label('Enviar a')
                            ->helperText('Por defecto, los emails del cliente. Pon el tuyo para ver cómo queda.')
                            ->default(fn (Cliente $record) => implode(', ', $record->emailsInforme()))
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Enviar')
                    ->action(function (Cliente $record, array $data, InformesSemanales $informes) {
                        $destinos = Cliente::separarEmails($data['para']);
                        if (! $destinos) {
                            Notification::make()->title('Ningún email válido')->danger()->send();
                            return;
                        }
                        try {
                            $informe = $informes->paraCliente($record, ...InformesSemanales::semanaAnterior());
                            Mail::to($destinos)->send(new InformeMail($informe['asunto'], $informe['cuerpo'], $record->color, $record->agencia_id));
                            Notification::make()
                                ->title('Informe enviado a ' . implode(', ', $destinos))
                                ->body($informe['sin_actividad'] ? 'Ojo: el cliente no tuvo actividad esa semana.' : null)
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('No se pudo enviar el informe')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

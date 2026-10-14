<?php

namespace App\Filament\Resources\Agencias\Schemas;

use App\Support\AgenciaActual;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AgenciaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Agencia')
                    ->columns(2)
                    ->components([
                        TextInput::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set, string $operation) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label('Identificador')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn () => ! AgenciaActual::esSuperadmin())
                            ->helperText('Se genera a partir del nombre.'),
                        TextInput::make('email_informes')
                            ->label('Email para el resumen semanal interno')
                            ->placeholder('direccion@agencia.com, cuentas@agencia.com')
                            ->maxLength(500)
                            ->helperText('Uno o varios, separados por coma. Vacío = los administradores de la agencia.'),
                        Toggle::make('activo')
                            ->label('Activa')
                            ->default(true)
                            ->helperText('Desactivada, ni sus admins ni sus clientes pueden entrar y no se le envían informes.')
                            ->visible(fn () => AgenciaActual::esSuperadmin()),
                    ]),

                Section::make('Marca')
                    ->description('La ven sus clientes en el dashboard y en los emails, y su equipo en el panel.')
                    ->columns(2)
                    ->components([
                        FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory('agencias')
                            ->visibility('public')
                            ->helperText('Sobre fondo claro: cabecera del dashboard, panel y emails.'),
                        FileUpload::make('logo_pie')
                            ->label('Logo para fondo oscuro (opcional)')
                            ->image()
                            ->disk('public')
                            ->directory('agencias')
                            ->visibility('public')
                            ->helperText('Para el pie del dashboard. Vacío = el logo normal.'),
                        TextInput::make('web')
                            ->label('Web')
                            ->url()
                            ->placeholder('https://agencia.com'),
                        TextInput::make('eslogan')
                            ->label('Eslogan')
                            ->placeholder('Agencia de marketing digital')
                            ->maxLength(120),
                    ]),

                Section::make('Apps de API')
                    ->description('Las apps de la agencia con las que se conectan las cuentas de sus clientes. '
                        . 'Se guardan cifradas. En cada plataforma hay que registrar como URL de retorno la que se indica.')
                    ->collapsible()
                    ->columns(2)
                    ->components([
                        TextInput::make('credenciales.meta_app_id')
                            ->label('Meta · App ID'),
                        self::secreto('credenciales.meta_app_secret', 'Meta · App Secret')
                            ->helperText('URL de retorno: ' . route('conexiones.meta.callback')),

                        TextInput::make('credenciales.google_client_id')
                            ->label('Google Ads · Client ID'),
                        self::secreto('credenciales.google_client_secret', 'Google Ads · Client Secret')
                            ->helperText('URL de retorno: ' . route('conexiones.google.callback')),
                        self::secreto('credenciales.google_developer_token', 'Google Ads · Developer token'),
                        TextInput::make('credenciales.google_login_customer_id')
                            ->label('Google Ads · ID de la MCC')
                            ->helperText('Solo dígitos o con guiones. Opcional.'),

                        TextInput::make('credenciales.linkedin_client_id')
                            ->label('LinkedIn · Client ID'),
                        self::secreto('credenciales.linkedin_client_secret', 'LinkedIn · Client Secret')
                            ->helperText('URL de retorno: ' . route('conexiones.linkedin.callback')),

                        TextInput::make('credenciales.spotify_client_id')
                            ->label('Spotify · Client ID'),
                        self::secreto('credenciales.spotify_client_secret', 'Spotify · Client Secret')
                            ->helperText('URL de retorno: ' . route('conexiones.spotify.callback')),
                    ]),

                // Solo al crear: el primer usuario de la agencia, que luego da
                // de alta al resto de su equipo y a sus clientes. No son campos
                // de la agencia: los recoge CreateAgencia::afterCreate().
                Section::make('Primer administrador')
                    ->description('Con este usuario entra la agencia en /admin.')
                    ->columns(3)
                    ->visibleOn('create')
                    ->components([
                        TextInput::make('admin_nombre')
                            ->label('Nombre')
                            ->required()
                            ->dehydrated(false),
                        TextInput::make('admin_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique('users', 'email')
                            ->dehydrated(false),
                        TextInput::make('admin_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(10)
                            ->dehydrated(false),
                    ]),
            ]);
    }

    private static function secreto(string $campo, string $etiqueta): TextInput
    {
        return TextInput::make($campo)
            ->label($etiqueta)
            ->password()
            ->revealable();
    }
}

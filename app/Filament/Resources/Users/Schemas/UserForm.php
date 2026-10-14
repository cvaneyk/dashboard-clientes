<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\AgenciaActual;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('es_admin')
                    ->label('Tipo de usuario')
                    ->native(false)
                    ->options([
                        '0' => 'Cliente — accede solo a su dashboard',
                        '1' => 'Administrador — accede al panel de gestión',
                    ])
                    ->default('0')
                    ->formatStateUsing(fn ($state) => $state ? '1' : '0')
                    ->required()
                    ->live()
                    ->helperText('Define a qué parte del sistema accede este usuario.'),
                // Solo el superadmin elige agencia (y puede crear otros
                // superadmins). Para el admin de una agencia, los usuarios que
                // crea son de la suya (User::creating); los de cliente, de la
                // agencia de su cliente (User::saving).
                Select::make('agencia_id')
                    ->label('Agencia')
                    ->relationship('agencia', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get) => $get('es_admin') === '1' && ! $get('es_superadmin'))
                    ->helperText('Para administradores. Los usuarios de cliente toman la agencia de su cliente.')
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                Toggle::make('es_superadmin')
                    ->label('Superadmin de la plataforma')
                    ->helperText('Ve y gestiona TODAS las agencias. Solo para quien opera la plataforma.')
                    ->live()
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
                TextInput::make('name')
                    ->label('Nombre')
                    ->required(),
                TextInput::make('email')
                    ->label('Email (usuario de acceso)')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('cliente_id')
                    ->label('Cliente asignado')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload()
                    ->helperText('Obligatorio para usuarios de tipo Cliente. Déjalo vacío para administradores.'),
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable()
                    // El modelo tiene el cast "hashed": se cifra sola al guardar.
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('En edición, déjalo vacío para mantener la actual.'),
            ]);
    }
}

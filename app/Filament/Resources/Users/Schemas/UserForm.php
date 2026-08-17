<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                    ->helperText('Define a qué parte del sistema accede este usuario.'),
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

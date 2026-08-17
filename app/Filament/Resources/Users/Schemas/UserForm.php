<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required(),
                TextInput::make('email')
                    ->label('Email (usuario de acceso)')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload()
                    ->helperText('Déjalo vacío solo para usuarios internos (administradores).'),
                Toggle::make('es_admin')
                    ->label('Es administrador (accede al panel /admin)')
                    ->default(false),
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

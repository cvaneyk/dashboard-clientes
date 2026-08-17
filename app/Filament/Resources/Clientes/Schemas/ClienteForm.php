<?php

namespace App\Filament\Resources\Clientes\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('slug', Str::slug((string) $state));
                    }),
                TextInput::make('slug')
                    ->label('Slug (identificador en la URL)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Se genera solo a partir del nombre; puedes editarlo.'),
                FileUpload::make('logo')
                    ->label('Logotipo del cliente')
                    ->image()
                    ->disk('public')
                    ->directory('logos')
                    ->visibility('public')
                    ->imageEditor()
                    ->helperText('Se mostrará en la cabecera de su dashboard.'),
                ColorPicker::make('color')
                    ->label('Color de marca'),
                Toggle::make('activo')
                    ->label('Activo')
                    ->default(true),
            ]);
    }
}

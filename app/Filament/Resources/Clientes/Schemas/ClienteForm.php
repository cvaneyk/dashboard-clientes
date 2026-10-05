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
                TextInput::make('email_informes')
                    ->label('Email para informes')
                    ->placeholder('marketing@cliente.com, director@cliente.com')
                    ->maxLength(500)
                    ->helperText('Uno o varios, separados por coma. Aquí llega el informe semanal de campañas.')
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        $partes = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                        $malos  = array_filter($partes, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL));
                        if ($malos) {
                            $fail('Email no válido: ' . implode(', ', $malos));
                        }
                    }),
                Toggle::make('informe_semanal')
                    ->label('Enviar informe semanal (lunes)')
                    ->helperText('Resumen de la semana anterior. La plantilla se edita en "Plantillas de email".')
                    ->default(false),
            ]);
    }
}

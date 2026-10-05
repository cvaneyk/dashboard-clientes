<?php

namespace App\Filament\Resources\PlantillasEmail\Schemas;

use App\Models\PlantillaEmail;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PlantillaEmailForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('nombre')
                    ->label('Plantilla')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('asunto')
                    ->label('Asunto')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Admite las mismas variables que el cuerpo, p. ej. {cliente} o {periodo}.'),
                RichEditor::make('cuerpo')
                    ->label('Cuerpo del email')
                    ->required()
                    ->toolbarButtons([
                        'bold', 'italic', 'underline', 'link', 'h2', 'h3',
                        'bulletList', 'orderedList', 'undo', 'redo',
                    ]),
                Placeholder::make('variables')
                    ->label('Variables disponibles')
                    ->content(fn (?PlantillaEmail $record) => self::variables($record)),
            ]);
    }

    private static function variables(?PlantillaEmail $record): HtmlString
    {
        $vars = PlantillaEmail::VARIABLES[$record?->clave] ?? [];

        $html = '<p style="margin-bottom:.5rem">Escríbelas tal cual, con las llaves. Las tablas van solas en su línea.</p><ul>';
        foreach ($vars as $nombre => $desc) {
            $html .= '<li><code>{' . e($nombre) . '}</code> — ' . e($desc) . '</li>';
        }

        return new HtmlString($html . '</ul>');
    }
}

<?php

namespace App\Filament\Resources\Clientes\Schemas;

use App\Services\Informes\InformesSemanales;
use App\Support\AgenciaActual;
use Carbon\Carbon;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                // Solo el superadmin elige agencia; para el admin de una agencia
                // el cliente es de la suya (Cliente::creating).
                Select::make('agencia_id')
                    ->label('Agencia')
                    ->relationship('agencia', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->visible(fn () => AgenciaActual::esSuperadmin()),
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

                // El comentario automático del informe describe qué cifra se ha
                // movido, pero no sabe por qué: eso se escribe aquí.
                Textarea::make('comentario_semanal')
                    ->label('Comentario del informe semanal')
                    ->rows(4)
                    ->helperText('Lo que los números no dicen: qué se cambió esa semana, por qué se movió algo y '
                        . 'qué vais a hacer. Sale en el email junto al comentario automático. Si lo dejas vacío, '
                        . 'el informe se envía igual, solo con la parte automática.')
                    ->columnSpanFull(),
                Select::make('comentario_semanal_semana')
                    ->label('El comentario es de esta semana')
                    ->options(fn () => self::semanasElegibles())
                    // Prerrellenado también al editar un cliente que aún no lo
                    // tiene: si quedara vacío, el comentario no saldría nunca.
                    ->formatStateUsing(fn ($state) => $state
                        ? Carbon::parse($state)->toDateString()
                        : self::semanaEnCurso()->toDateString())
                    ->helperText('El comentario solo aparece en el informe de la semana elegida. Así una nota '
                        . 'de hace un mes no se repite cada lunes.'),
            ]);
    }

    /** Lunes de la semana en curso, que es la que informará el lunes que viene. */
    private static function semanaEnCurso(): Carbon
    {
        return Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * Semanas elegibles para la nota: la que está en curso y las tres anteriores.
     *
     * @return array<string,string>
     */
    private static function semanasElegibles(): array
    {
        $enCurso = self::semanaEnCurso();
        $out     = [];

        for ($i = 0; $i < 4; $i++) {
            $lunes = $enCurso->copy()->subWeeks($i);

            $out[$lunes->toDateString()] = 'Semana del '
                . InformesSemanales::periodo($lunes, $lunes->copy()->addDays(6))
                . ($i === 0 ? ' (en curso)' : '');
        }

        return $out;
    }
}

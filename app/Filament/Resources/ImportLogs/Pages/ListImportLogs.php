<?php

namespace App\Filament\Resources\ImportLogs\Pages;

use App\Filament\Resources\ImportLogs\ImportLogResource;
use App\Models\Cuenta;
use App\Services\Import\CampaignImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListImportLogs extends ListRecords
{
    protected static string $resource = ImportLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importar')
                ->label('Importar informe')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar informe de campañas')
                ->modalSubmitActionLabel('Importar')
                // NOTA v5: si diera error, cambia ->schema( por ->form(
                ->schema([
                    Select::make('cuenta_id')
                        ->label('Canal')
                        ->options(fn () => Cuenta::query()->with('cliente')->get()
                            ->mapWithKeys(fn ($c) => [
                                $c->id => trim(($c->cliente?->nombre ?? 'Sin cliente') . ' · ' . $c->plataforma . ' · ' . $c->nombre_cuenta),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    FileUpload::make('archivo')
                        ->label('Archivo (CSV de Google o Excel de Meta/TikTok)')
                        ->disk('local')
                        ->directory('imports')
                        ->storeFiles()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $cuenta = Cuenta::findOrFail($data['cuenta_id']);
                    $path   = Storage::disk('local')->path($data['archivo']);

                    try {
                        $res = app(CampaignImporter::class)->import($cuenta, $path);

                        Notification::make()
                            ->title('Importación completada')
                            ->body(sprintf(
                                '%d filas · %d líneas nuevas · %s € · %s → %s%s',
                                $res['filas'],
                                $res['lineas_nuevas'],
                                number_format($res['gasto_real'], 2, ',', '.'),
                                $res['periodo_inicio'] ?? '?',
                                $res['periodo_fin'] ?? '?',
                                $res['filas_error'] > 0 ? sprintf(' · %d filas descartadas', $res['filas_error']) : ''
                            ))
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error al importar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}

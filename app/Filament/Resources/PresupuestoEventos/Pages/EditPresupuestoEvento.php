<?php

namespace App\Filament\Resources\PresupuestoEventos\Pages;

use App\Filament\Resources\PresupuestoEventos\PresupuestoEventoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPresupuestoEvento extends EditRecord
{
    protected static string $resource = PresupuestoEventoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

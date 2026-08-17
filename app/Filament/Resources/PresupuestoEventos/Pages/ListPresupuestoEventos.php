<?php

namespace App\Filament\Resources\PresupuestoEventos\Pages;

use App\Filament\Resources\PresupuestoEventos\PresupuestoEventoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPresupuestoEventos extends ListRecords
{
    protected static string $resource = PresupuestoEventoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\Agencias\Pages;

use App\Filament\Resources\Agencias\AgenciaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgencias extends ListRecords
{
    protected static string $resource = AgenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

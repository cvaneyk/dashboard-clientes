<?php

namespace App\Filament\Resources\Lineas\Pages;

use App\Filament\Resources\Lineas\LineaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLineas extends ListRecords
{
    protected static string $resource = LineaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

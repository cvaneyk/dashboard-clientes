<?php

namespace App\Filament\Resources\Lineas\Pages;

use App\Filament\Resources\Lineas\LineaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLinea extends EditRecord
{
    protected static string $resource = LineaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

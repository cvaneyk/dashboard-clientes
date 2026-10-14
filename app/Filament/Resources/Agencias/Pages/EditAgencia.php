<?php

namespace App\Filament\Resources\Agencias\Pages;

use App\Filament\Resources\Agencias\AgenciaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAgencia extends EditRecord
{
    protected static string $resource = AgenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

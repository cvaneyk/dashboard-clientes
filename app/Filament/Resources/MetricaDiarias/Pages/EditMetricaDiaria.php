<?php

namespace App\Filament\Resources\MetricaDiarias\Pages;

use App\Filament\Resources\MetricaDiarias\MetricaDiariaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMetricaDiaria extends EditRecord
{
    protected static string $resource = MetricaDiariaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

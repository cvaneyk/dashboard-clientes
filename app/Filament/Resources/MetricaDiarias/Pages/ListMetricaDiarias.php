<?php

namespace App\Filament\Resources\MetricaDiarias\Pages;

use App\Filament\Resources\MetricaDiarias\MetricaDiariaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMetricaDiarias extends ListRecords
{
    protected static string $resource = MetricaDiariaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

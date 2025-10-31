<?php

namespace App\Filament\Resources\ServiceFeeResource\Pages;

use App\Filament\Resources\ServiceFeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceFees extends ListRecords
{
    protected static string $resource = ServiceFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

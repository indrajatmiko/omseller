<?php

namespace App\Filament\Resources\ServiceFeeResource\Pages;

use App\Filament\Resources\ServiceFeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceFee extends EditRecord
{
    protected static string $resource = ServiceFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

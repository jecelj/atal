<?php

namespace App\Filament\Resources\YachtModelResource\Pages;

use App\Filament\Resources\YachtModelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditYachtModel extends EditRecord
{
    protected static string $resource = YachtModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

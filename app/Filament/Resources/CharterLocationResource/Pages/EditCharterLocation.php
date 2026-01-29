<?php

namespace App\Filament\Resources\CharterLocationResource\Pages;

use App\Filament\Resources\CharterLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCharterLocation extends EditRecord
{
    protected static string $resource = CharterLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

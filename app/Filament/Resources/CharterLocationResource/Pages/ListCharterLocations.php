<?php

namespace App\Filament\Resources\CharterLocationResource\Pages;

use App\Filament\Resources\CharterLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCharterLocations extends ListRecords
{
    protected static string $resource = CharterLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

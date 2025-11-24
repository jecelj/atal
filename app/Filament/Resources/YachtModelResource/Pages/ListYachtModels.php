<?php

namespace App\Filament\Resources\YachtModelResource\Pages;

use App\Filament\Resources\YachtModelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListYachtModels extends ListRecords
{
    protected static string $resource = YachtModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add Yacht Model'),
        ];
    }
}

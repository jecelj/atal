<?php

namespace App\Filament\Resources\CharterYachtResource\Pages;

use App\Filament\Resources\CharterYachtResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCharterYacht extends CreateRecord
{
    protected static string $resource = CharterYachtResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

<?php

namespace App\Filament\Resources\UsedYachtResource\Pages;

use App\Filament\Resources\UsedYachtResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUsedYacht extends CreateRecord
{
    protected static string $resource = UsedYachtResource::class;

    protected function afterCreate(): void
    {
        // The site relationship is saved after the model's initial "saved" event.
        // Queue once more so the first sync honours the selected site assignment.
        app(\App\Services\QueuedWordPressSyncService::class)
            ->queueModelForActiveSites($this->getRecord());
    }
}

<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

class SyncObserver
{
    /**
     * Handle the Model "saved" event.
     */
    public function saved(Model $model): void
    {
        app(\App\Services\QueuedWordPressSyncService::class)->queueModelForActiveSites($model);

        // Auto-check status (Image optimization & Translations)
        try {
            $service = app(\App\Services\StatusCheckService::class);
            $service->checkAndUpdateStatus($model);
        } catch (\Exception $e) {
            // Log error but don't block
            \Illuminate\Support\Facades\Log::error("SyncObserver Status Check Error: " . $e->getMessage());
        }
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        app(\App\Services\QueuedWordPressSyncService::class)->queueModelForActiveSites($model, true);
    }
}

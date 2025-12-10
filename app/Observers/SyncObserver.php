<?php

namespace App\Observers;

use App\Models\SyncSite;
use App\Models\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use App\Models\NewYacht;
use App\Models\UsedYacht;
use App\Models\News;

class SyncObserver
{
    /**
     * Handle the Model "saved" event.
     */
    public function saved(Model $model): void
    {
        $this->updateSyncStatus($model);

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
        $this->updateSyncStatus($model);
    }

    protected function updateSyncStatus(Model $model): void
    {
        // Determine Model Type Key
        $type = match (get_class($model)) {
            NewYacht::class => 'new_yacht',
            UsedYacht::class => 'used_yacht',
            News::class => 'news',
            default => null,
        };

        if (!$type) {
            return;
        }

        // Get all active sites
        $sites = SyncSite::active()->get();

        foreach ($sites as $site) {
            // Find existing status or create new one
            // We force status to 'pending' regardless of previous state.
            // This ensures "Dirty" count increases in Dashboard.

            SyncStatus::updateOrCreate(
                [
                    'sync_site_id' => $site->id,
                    'model_type' => $type,
                    'model_id' => $model->id,
                ],
                [
                    'status' => 'pending',
                    // We DO NOT update content_hash here. 
                    // Hash is calculated only during actual sync. 
                    // Setting status to pending is enough to trigger "Dirty" count.
                    'error_message' => null, // Clear previous errors if any
                ]
            );
        }
    }
}

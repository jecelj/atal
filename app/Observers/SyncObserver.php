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
            // Check if item is "Published/Active"
            $isPublished = false;

            if ($model instanceof NewYacht || $model instanceof UsedYacht) {
                $isPublished = ($model->state === 'published');
            } elseif ($model instanceof News) {
                $isPublished = (bool) $model->is_active;
            }

            // Logic:
            // 1. If Published: Always set Pending (needs sync).
            // 2. If Not Published: Only set Pending IF it was previously Synced (needs delete request).
            // 3. If Not Published & Not Synced: Do nothing (keep invisible).

            if ($isPublished) {
                SyncStatus::updateOrCreate(
                    [
                        'sync_site_id' => $site->id,
                        'model_type' => $type,
                        'model_id' => $model->id,
                    ],
                    [
                        'status' => 'pending',
                        'error_message' => null,
                    ]
                );
            } else {
                // Check if it exists and was synced
                $existing = SyncStatus::where('sync_site_id', $site->id)
                    ->where('model_type', $type)
                    ->where('model_id', $model->id)
                    ->where('status', 'synced')
                    ->first();

                if ($existing) {
                    // It was synced, now it's draft -> Needs sync (to process deletion)
                    $existing->update(['status' => 'pending']);
                }
                // Else: Draft and never synced -> Ignore.
            }
        }
    }
}

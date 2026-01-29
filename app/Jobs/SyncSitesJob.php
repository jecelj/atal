<?php

namespace App\Jobs;

use App\Models\SyncSite;
use App\Services\WordPressSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SyncSitesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?int $siteId;
    protected string $sessionKey;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $siteId, string $sessionKey, protected bool $force = false, protected ?string $type = null)
    {
        $this->siteId = $siteId;
        $this->sessionKey = $sessionKey;
    }

    /**
     * Execute the job.
     */
    public function handle(WordPressSyncService $syncService): void
    {
        // Get sites to sync
        if ($this->siteId) {
            $sites = SyncSite::where('id', $this->siteId)->where('is_active', true)->get();
        } else {
            $sites = SyncSite::active()->ordered()->get();
        }

        $totalSites = $sites->count();
        $results = [];

        // Initialize progress
        Cache::put($this->sessionKey, [
            'progress' => 0,
            'current_site' => null,
            'completed' => false,
            'results' => [],
            'total' => $totalSites,
        ], now()->addMinutes(30));

        foreach ($sites as $index => $site) {
            // Update current site
            Cache::put($this->sessionKey, [
                'progress' => (($index) / $totalSites) * 100,
                'current_site' => $site->name,
                'completed' => false,
                'results' => $results,
                'total' => $totalSites,
            ], now()->addMinutes(30));

            // Sync this site
            $result = $syncService->syncSite($site, $this->force, $this->type);
            $results[] = [
                'site' => $site->name,
                'success' => $result['success'],
                'message' => $result['message'],
            ];

            // Update progress with result
            Cache::put($this->sessionKey, [
                'progress' => (($index + 1) / $totalSites) * 100,
                'current_site' => $site->name,
                'completed' => false,
                'results' => $results,
                'total' => $totalSites,
            ], now()->addMinutes(30));
        }

        // Mark as completed
        Cache::put($this->sessionKey, [
            'progress' => 100,
            'current_site' => null,
            'completed' => true,
            'results' => $results,
            'total' => $totalSites,
        ], now()->addMinutes(30));
    }
}

<?php

namespace App\Livewire;

use App\Models\SyncSite;
use App\Services\WordPressSyncService;
use Livewire\Component;

class SyncProgress extends Component
{
    public $sites = [];
    public $currentSite = null;
    public $progress = 0;
    public $completed = false;
    public $results = [];

    public function mount($siteId = null)
    {
        if ($siteId) {
            // Single site sync
            $this->sites = SyncSite::where('id', $siteId)->where('is_active', true)->get();
        } else {
            // Sync all
            $this->sites = SyncSite::active()->ordered()->get();
        }
    }

    public function startSync()
    {
        $syncService = app(WordPressSyncService::class);
        $totalSites = $this->sites->count();

        foreach ($this->sites as $index => $site) {
            $this->currentSite = $site->name;
            $this->progress = (($index + 1) / $totalSites) * 100;

            // Sync this site
            $result = $syncService->syncSite($site);
            $this->results[] = [
                'site' => $site->name,
                'success' => $result['success'],
                'message' => $result['message'],
            ];

            // Force Livewire to update UI
            $this->dispatch('progress-updated');

            // Small delay to show progress
            usleep(100000); // 0.1 second
        }

        $this->completed = true;
        $this->currentSite = null;
    }

    public function render()
    {
        return view('livewire.sync-progress');
    }
}

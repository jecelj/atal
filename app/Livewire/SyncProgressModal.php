<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class SyncProgressModal extends Component
{
    public string $sessionKey;
    public $progress = 0;
    public $currentSite = null;
    public $completed = false;
    public $results = [];
    public $total = 0;

    public function mount(string $sessionKey)
    {
        $this->sessionKey = $sessionKey;
    }

    public function startSync()
    {
        // Prevent timeout during sync
        set_time_limit(0);
        ini_set('max_execution_time', 0); // Double measure

        // Dispatch the job synchronously
        // Since this is called via wire:init, it runs in a separate request after the modal opens.
        // The user sees the spinner/progress while this runs.
        \App\Jobs\SyncSitesJob::dispatchSync(null, $this->sessionKey);

        // After job finishes, update one last time
        $this->updateProgress();
    }

    public function updateProgress()
    {
        $data = Cache::get($this->sessionKey, [
            'progress' => 0,
            'current_site' => null,
            'completed' => false,
            'results' => [],
            'total' => 0,
        ]);

        $this->progress = $data['progress'];
        $this->currentSite = $data['current_site'];
        $this->completed = $data['completed'];
        $this->results = $data['results'];
        $this->total = $data['total'];
    }

    public function render()
    {
        return view('livewire.sync-progress-modal');
    }
}

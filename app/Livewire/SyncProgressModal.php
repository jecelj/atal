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

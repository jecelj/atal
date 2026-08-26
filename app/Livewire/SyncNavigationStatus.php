<?php

namespace App\Livewire;

use App\Models\WordPressSyncOutbox;
use Livewire\Component;

class SyncNavigationStatus extends Component
{
    public bool $isProcessing = false;

    public int $activeOperationCount = 0;

    public function mount(): void
    {
        $this->setStatus();
    }

    public function refreshStatus(): void
    {
        $previouslyProcessing = $this->isProcessing;
        $previousCount = $this->activeOperationCount;

        $this->setStatus();

        if ($previouslyProcessing === $this->isProcessing && $previousCount === $this->activeOperationCount) {
            return;
        }

        $this->dispatch('sync-navigation-status-changed', isProcessing: $this->isProcessing);
    }

    public function render()
    {
        return view('livewire.sync-navigation-status');
    }

    private function setStatus(): void
    {
        $this->activeOperationCount = WordPressSyncOutbox::query()
            ->whereIn('state', ['pending', 'media'])
            ->count();
        $this->isProcessing = $this->activeOperationCount > 0;
    }
}

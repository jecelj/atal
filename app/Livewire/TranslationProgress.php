<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Cache;

class TranslationProgress extends Component
{
    public $yachtId;
    public $logs = [];
    public $isCompleted = false;

    public function mount($yachtId)
    {
        $this->yachtId = $yachtId;
        $this->refreshLogs();
    }

    public function refreshLogs()
    {
        $key = "translation_progress_{$this->yachtId}";
        $this->logs = Cache::get($key, []);

        // Check if completed
        if (!empty($this->logs)) {
            $lastLog = end($this->logs);
            if ($lastLog['status'] === 'completed') {
                $this->isCompleted = true;
            }
        }
    }

    public function closeAndReload()
    {
        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.translation-progress');
    }
}

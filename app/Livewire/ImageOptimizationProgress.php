<?php

namespace App\Livewire;

use App\Models\News;
use App\Models\Yacht;
use App\Services\ImageOptimizationService;
use Livewire\Component;

class ImageOptimizationProgress extends Component
{
    public $recordId;
    public $type = 'yacht'; // 'yacht' or 'news' or 'used_yacht'
    public $logs = [];
    public $stats = [];
    public $isCompleted = false;
    public $isStarted = false;

    public function mount($recordId, $type = 'yacht')
    {
        $this->recordId = $recordId;
        $this->type = $type;
        $this->dispatch('open-modal', id: 'image-optimization-progress');
    }

    public function startOptimization()
    {
        $this->isStarted = true;
        // In this implementation, we do it in one shot because the service processes all.
        // If it times out, we might need to refactor the service.
        $this->optimize();
    }

    public function optimize()
    {
        $record = $this->getRecord();
        if (!$record) {
            $this->addLog("Error: Record not found", 'error');
            $this->isCompleted = true;
            return;
        }

        try {
            $this->addLog("Starting optimization for " . class_basename($record) . "...", 'info');

            // This might take time
            $service = app(ImageOptimizationService::class);
            $stats = $service->processYachtImages($record);

            $this->stats = $stats;

            $message = "Processed: {$stats['processed']}, Renamed: {$stats['renamed']}, Converted: {$stats['converted']}, Resized: {$stats['resized']}, Errors: {$stats['errors']}";
            $this->addLog($message, 'done');

            if ($stats['errors'] > 0) {
                $this->addLog("Optimization completed with some errors.", 'warning');
            } else {
                $this->addLog("Optimization completed successfully!", 'completed');
            }

        } catch (\Exception $e) {
            $this->addLog("Error: " . $e->getMessage(), 'error');
        }

        $this->isCompleted = true;
    }

    protected function getRecord()
    {
        if ($this->type === 'news') {
            return News::find($this->recordId);
        } elseif ($this->type === 'used_yacht') {
            return \App\Models\UsedYacht::find($this->recordId);
        } elseif ($this->type === 'charter_yacht') {
            return \App\Models\CharterYacht::find($this->recordId);
        }
        return \App\Models\NewYacht::find($this->recordId);
    }

    protected function addLog($message, $status)
    {
        $this->logs[] = [
            'message' => $message,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function closeAndReload()
    {
        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.image-optimization-progress');
    }
}

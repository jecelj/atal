<?php

namespace App\Jobs;

use App\Models\Yacht;
use App\Services\ImageOptimizationService;
use App\Services\StatusCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizeYachtImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes per yacht
    public $tries = 1; // Only try once

    /**
     * Create a new job instance.
     */
    public function __construct(
        public \Illuminate\Database\Eloquent\Model $model,
        public bool $force = false
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Increase memory limit
        ini_set('memory_limit', '512M');

        Log::info("OptimizeImages: Starting optimization for model " . class_basename($this->model) . " ID {$this->model->id}");

        try {
            // Check if already optimized (skip if not forced)
            if (!$this->force && $this->model->img_opt_status === true) {
                Log::info("OptimizeImages: Model {$this->model->id} already optimized, skipping");
                return;
            }

            // Run optimization
            $service = app(ImageOptimizationService::class);
            $stats = $service->processYachtImages($this->model, $this->force);

            Log::info("OptimizeImages: Completed for model {$this->model->id}", $stats);

            // Update status
            $statusService = app(StatusCheckService::class);
            if (method_exists($statusService, 'checkAndUpdateStatus')) {
                $statusService->checkAndUpdateStatus($this->model);
            }

            Log::info("OptimizeImages: Successfully optimized model {$this->model->id}");

        } catch (\Throwable $e) {
            Log::error("OptimizeImages: Failed for model {$this->model->id}: " . $e->getMessage());
            Log::error("OptimizeImages: Stack trace: " . $e->getTraceAsString());

            // Re-throw to mark job as failed
            throw $e;
        }
    }
}

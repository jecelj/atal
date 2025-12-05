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
        public Yacht $yacht,
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

        Log::info("OptimizeYachtImages: Starting optimization for yacht {$this->yacht->id}");

        try {
            // Check if already optimized (skip if not forced)
            if (!$this->force && $this->yacht->img_opt_status === true) {
                Log::info("OptimizeYachtImages: Yacht {$this->yacht->id} already optimized, skipping");
                return;
            }

            // Run optimization
            $service = app(ImageOptimizationService::class);
            $stats = $service->processYachtImages($this->yacht);

            Log::info("OptimizeYachtImages: Completed for yacht {$this->yacht->id}", $stats);

            // Update status
            $statusService = app(StatusCheckService::class);
            $statusService->checkAndUpdateStatus($this->yacht);

            Log::info("OptimizeYachtImages: Successfully optimized yacht {$this->yacht->id}");

        } catch (\Throwable $e) {
            Log::error("OptimizeYachtImages: Failed for yacht {$this->yacht->id}: " . $e->getMessage());
            Log::error("OptimizeYachtImages: Stack trace: " . $e->getTraceAsString());

            // Re-throw to mark job as failed
            throw $e;
        }
    }
}

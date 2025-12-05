<?php

namespace App\Console\Commands;

use App\Jobs\OptimizeYachtImages;
use App\Models\Yacht;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OptimizeContent extends Command
{
    protected $signature = 'atal:optimize-content 
                            {--force : Force re-optimization of already optimized content}
                            {--only-unoptimized : Only process content that is not yet optimized}
                            {--type= : Content type to optimize (yachts or news, default: all)}';

    protected $description = 'Queue image optimization jobs for all content';

    public function handle()
    {
        $force = $this->option('force');
        $onlyUnoptimized = $this->option('only-unoptimized');
        $type = $this->option('type');

        $this->info('═══════════════════════════════════════════════');
        $this->info('   Image Optimization Queue');
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        $totalQueued = 0;

        // Optimize Yachts (New and Used)
        if (!$type || $type === 'yachts') {
            $this->info('⛵ Processing Yachts...');

            // Process New Yachts
            $newYachtsQuery = \App\Models\NewYacht::query();
            if ($onlyUnoptimized) {
                $newYachtsQuery->where(function ($q) {
                    $q->where('img_opt_status', false)
                        ->orWhereNull('img_opt_status');
                });
            }
            $newYachts = $newYachtsQuery->get();

            foreach ($newYachts as $yacht) {
                if ($force)
                    Log::info("Dispatching optimization for NewYacht {$yacht->id} with FORCE=TRUE");
                OptimizeYachtImages::dispatch($yacht, $force);
                $totalQueued++;
            }
            $this->line("  • Queued {$newYachts->count()} new yachts");

            // Process Used Yachts
            $usedYachtsQuery = \App\Models\UsedYacht::query();
            if ($onlyUnoptimized) {
                $usedYachtsQuery->where(function ($q) {
                    $q->where('img_opt_status', false)
                        ->orWhereNull('img_opt_status');
                });
            }
            $usedYachts = $usedYachtsQuery->get();

            foreach ($usedYachts as $yacht) {
                if ($force)
                    Log::info("Dispatching optimization for UsedYacht {$yacht->id} with FORCE=TRUE");
                OptimizeYachtImages::dispatch($yacht, $force);
                $totalQueued++;
            }
            $this->line("  • Queued {$usedYachts->count()} used yachts");

            $this->newLine();
        }

        // Optimize News
        if (!$type || $type === 'news') {
            $this->info('📰 Processing News...');

            $query = News::query();

            if ($onlyUnoptimized) {
                $query->where(function ($q) {
                    $q->where('img_opt_status', false)
                        ->orWhereNull('img_opt_status');
                });
            }

            $news = $query->get();

            foreach ($news as $newsItem) {
                OptimizeYachtImages::dispatch($newsItem, $force);
                $totalQueued++;
            }

            $this->line("  • Queued {$news->count()} news items");
            $this->newLine();
        }

        $this->info("✓ Total queued: {$totalQueued} optimization jobs");
        $this->newLine();

        $this->comment('💡 Run the queue worker to process:');
        $this->line('   php artisan queue:work --timeout=600');
        $this->newLine();

        $this->comment('📊 Check status:');
        $this->line('   php artisan atal:translate-status');

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');

        return 0;
    }
}

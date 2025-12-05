<?php

namespace App\Console\Commands;

use App\Jobs\OptimizeYachtImages;
use App\Models\Yacht;
use App\Models\News;
use Illuminate\Console\Command;

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

        // Optimize Yachts
        if (!$type || $type === 'yachts') {
            $this->info('⛵ Processing Yachts...');

            $query = Yacht::query();

            if ($onlyUnoptimized) {
                $query->where(function ($q) {
                    $q->where('img_opt_status', false)
                        ->orWhereNull('img_opt_status');
                });
            }

            $yachts = $query->get();

            foreach ($yachts as $yacht) {
                OptimizeYachtImages::dispatch($yacht, $force);
                $totalQueued++;
            }

            $this->line("  • Queued {$yachts->count()} yachts");
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

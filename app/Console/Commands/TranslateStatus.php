<?php

namespace App\Console\Commands;

use App\Models\Yacht;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TranslateStatus extends Command
{
    protected $signature = 'atal:translate-status';
    protected $description = 'Show translation status for all content';

    public function handle()
    {
        $this->info('═══════════════════════════════════════════════');
        $this->info('   Translation Status Overview');
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        // Queue Status
        $this->info('📋 Queue Status:');
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $this->line("  • Pending jobs: <fg=yellow>{$pendingJobs}</>");
        $this->line("  • Failed jobs: " . ($failedJobs > 0 ? "<fg=red>{$failedJobs}</>" : "<fg=green>{$failedJobs}</>"));
        $this->newLine();

        // Yachts Translation Status
        $this->info('⛵ Yachts Translation Status:');
        $totalYachts = Yacht::count();
        $translatedYachts = Yacht::where('translation_status', true)->count();
        $untranslatedYachts = Yacht::where('translation_status', false)->orWhereNull('translation_status')->count();

        $percentage = $totalYachts > 0 ? round(($translatedYachts / $totalYachts) * 100, 1) : 0;

        $this->line("  • Total: {$totalYachts}");
        $this->line("  • Translated: <fg=green>{$translatedYachts}</>");
        $this->line("  • Untranslated: <fg=yellow>{$untranslatedYachts}</>");
        $this->line("  • Progress: {$percentage}%");

        // Progress bar
        if ($totalYachts > 0) {
            $barWidth = 40;
            $filledWidth = (int) (($translatedYachts / $totalYachts) * $barWidth);
            $emptyWidth = $barWidth - $filledWidth;
            $bar = str_repeat('█', $filledWidth) . str_repeat('░', $emptyWidth);
            $this->line("  [{$bar}]");
        }
        $this->newLine();

        // News Translation Status
        $this->info('📰 News Translation Status:');
        $totalNews = News::count();
        $translatedNews = News::where('translation_status', true)->count();
        $untranslatedNews = News::where('translation_status', false)->orWhereNull('translation_status')->count();

        $newsPercentage = $totalNews > 0 ? round(($translatedNews / $totalNews) * 100, 1) : 0;

        $this->line("  • Total: {$totalNews}");
        $this->line("  • Translated: <fg=green>{$translatedNews}</>");
        $this->line("  • Untranslated: <fg=yellow>{$untranslatedNews}</>");
        $this->line("  • Progress: {$newsPercentage}%");

        // Progress bar
        if ($totalNews > 0) {
            $barWidth = 40;
            $filledWidth = (int) (($translatedNews / $totalNews) * $barWidth);
            $emptyWidth = $barWidth - $filledWidth;
            $bar = str_repeat('█', $filledWidth) . str_repeat('░', $emptyWidth);
            $this->line("  [{$bar}]");
        }
        $this->newLine();

        // Recent Activity
        $this->info('🕒 Recent Translation Updates:');
        $recentYachts = Yacht::where('translation_status', true)
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'updated_at']);

        if ($recentYachts->isEmpty()) {
            $this->line('  <fg=gray>No recent translations</>');
        } else {
            foreach ($recentYachts as $yacht) {
                $name = is_array($yacht->name) ? ($yacht->name['en'] ?? 'N/A') : $yacht->name;
                $time = $yacht->updated_at->diffForHumans();
                $this->line("  • Yacht #{$yacht->id}: {$name} - <fg=gray>{$time}</>");
            }
        }
        $this->newLine();

        // Recommendations
        if ($failedJobs > 0) {
            $this->warn('⚠️  You have failed jobs. Check with: php artisan queue:failed');
        }

        if ($pendingJobs > 0) {
            $this->info('💡 Queue worker should be running: php artisan queue:work');
        } else {
            $this->comment('✓ No pending jobs in queue');
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');

        return 0;
    }
}

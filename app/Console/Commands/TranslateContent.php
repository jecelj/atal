<?php

namespace App\Console\Commands;

use App\Jobs\TranslateYachtContent;
use App\Models\Yacht;
use Illuminate\Console\Command;

class TranslateContent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atal:translate-content {--force : Force re-translation of existing content}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue translation jobs for all yachts using OpenAI';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queuing translation jobs for all yachts...');

        $yachts = Yacht::all();
        $force = $this->option('force');

        foreach ($yachts as $yacht) {
            TranslateYachtContent::dispatch($yacht, $force);
        }

        $this->info("Queued {$yachts->count()} translation jobs.");
        $this->info('Run "php artisan queue:work" to process them.');
    }
}

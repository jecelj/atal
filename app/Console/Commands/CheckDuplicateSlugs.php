<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Yacht;
use App\Models\News;

class CheckDuplicateSlugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yachts:check-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for duplicate slugs in Yachts and News tables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for duplicate slugs...');

        $this->checkTable('yachts', 'Yachts');
        $this->checkTable('news', 'News');

        $this->info('Check complete.');
    }

    protected function checkTable($table, $label)
    {
        $duplicates = DB::table($table)
            ->select('slug', DB::raw('count(*) as total'))
            ->groupBy('slug')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info("✔ No duplicate slugs found in {$label}.");
        } else {
            $this->error("✘ Found duplicate slugs in {$label}:");
            $this->table(
                ['Slug', 'Count'],
                $duplicates->map(fn($d) => [$d->slug, $d->total])
            );

            // Optional: Show details
            if ($this->confirm("Show detailed IDs for duplicates in {$label}?", false)) {
                foreach ($duplicates as $dup) {
                    $ids = DB::table($table)->where('slug', $dup->slug)->pluck('id')->toArray();
                    $this->line("  - {$dup->slug}: IDs [" . implode(', ', $ids) . "]");
                }
            }
        }
    }
}

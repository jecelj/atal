<?php

namespace App\Console\Commands;

use App\Models\CharterYacht;
use App\Models\Language;
use App\Models\NewYacht;
use App\Models\SyncSite;
use App\Models\UsedYacht;
use App\Models\Yacht;
use App\Services\QueuedWordPressSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

class NormalizeYachtTitles extends Command
{
    /**
     * @var array<string, class-string<Yacht>>
     */
    private const TYPES = [
        'new_yacht' => NewYacht::class,
        'used_yacht' => UsedYacht::class,
        'charter_yacht' => CharterYacht::class,
    ];

    protected $signature = 'yachts:normalize-titles
                            {--sync : Force-queue WordPress synchronization after normalizing names}
                            {--dry-run : Report changes without writing to the database or queue}';

    protected $description = 'Copy English yacht titles to every language for new, used, and charter yachts';

    public function handle(QueuedWordPressSyncService $sync): int
    {
        $locales = Language::query()->pluck('code')->all();
        $backup = [
            'generated_at' => now()->toIso8601String(),
            'purpose' => 'Pre-normalization backup of yacht title translations',
            'records' => [],
        ];

        foreach (self::TYPES as $type => $model) {
            foreach ($model::query()->orderBy('id')->cursor() as $yacht) {
                $backup['records'][] = [
                    'type' => $type,
                    'id' => $yacht->id,
                    'slug' => $yacht->slug,
                    'name' => $yacht->getTranslations('name'),
                ];
            }
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: inspected %d yacht titles. No data was changed.', count($backup['records'])));

            return self::SUCCESS;
        }

        $backupPath = 'backups/yacht-title-translations-'.now()->format('Ymd-His').'.json';

        try {
            Storage::disk('local')->makeDirectory('backups');
            Storage::disk('local')->put(
                $backupPath,
                json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        } catch (JsonException $exception) {
            $this->error('Backup could not be encoded: '.$exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Backup could not be written: '.$exception->getMessage());

            return self::FAILURE;
        }

        $updated = [];
        $skipped = [];

        foreach (self::TYPES as $type => $model) {
            $updated[$type] = 0;
            $skipped[$type] = 0;

            foreach ($model::query()->orderBy('id')->cursor() as $yacht) {
                if (! $yacht->synchronizeNameTranslations($locales)) {
                    $skipped[$type]++;
                    continue;
                }

                // The values were normalized explicitly above. Saving quietly
                // prevents an individual observer job for every record; the
                // force sync below queues each supported vessel exactly once.
                $yacht->saveQuietly();
                $updated[$type]++;
            }
        }

        $this->info('Backup saved to storage/app/private/'.$backupPath);
        $this->table(['Type', 'Updated', 'Already normalized / no name'], array_map(
            fn (string $type) => [$type, $updated[$type], $skipped[$type]],
            array_keys(self::TYPES),
        ));

        if (! $this->option('sync')) {
            $this->info('No WordPress sync was queued (run again with --sync to queue it).');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach (SyncSite::active()->ordered()->cursor() as $site) {
            foreach (array_keys(self::TYPES) as $type) {
                $result = $sync->queueSite($site, true, $type);
                $queued += $result['queued'];
            }
        }

        $this->info("Force-queued {$queued} WordPress operations for new, used, and charter yachts.");

        return self::SUCCESS;
    }
}

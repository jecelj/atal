<?php

namespace App\Services;

use App\Jobs\ProcessWordPressSyncOperation;
use App\Models\CharterYacht;
use App\Models\FormFieldConfiguration;
use App\Models\NewYacht;
use App\Models\News;
use App\Models\SyncSite;
use App\Models\SyncStatus;
use App\Models\UsedYacht;
use App\Models\WordPressSyncOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class QueuedWordPressSyncService
{
    public function __construct(private readonly WordPressSyncService $legacy)
    {
    }

    public function queueSite(SyncSite $site, bool $force = false, ?string $specificType = null): array
    {
        $errors = [];
        $this->queueConfigIfChanged($site, $errors);
        $queued = 0;

        foreach ($this->types() as $type => $class) {
            if ($specificType && $specificType !== $type) {
                continue;
            }

            foreach ($class::cursor() as $record) {
                if ($this->legacy->isFilteredOut($record, $site)) {
                    if ($this->hasStatus($site, $type, $record->id)) {
                        $this->queueDelete($site, $type, $record->id);
                        $queued++;
                    }
                    continue;
                }

                $payload = $this->legacy->preparePayload($record, $site, $type);
                $hash = md5(json_encode($payload));
                $status = SyncStatus::firstOrNew([
                    'sync_site_id' => $site->id,
                    'model_type' => $type,
                    'model_id' => $record->id,
                ]);

                if (!$force && $status->status === 'synced' && $status->content_hash === $hash && $status->sync_version >= 2) {
                    continue;
                }

                $this->queueUpsert($site, $record, $type, $payload, $hash);
                $queued++;
            }
        }

        if (!$specificType) {
            foreach (SyncStatus::where('sync_site_id', $site->id)->cursor() as $status) {
                $class = $this->types()[$status->model_type] ?? null;
                if (!$class || !$class::find($status->model_id)) {
                    $this->queueDelete($site, $status->model_type, $status->model_id);
                    $queued++;
                }
            }
        }

        $site->update([
            'last_synced_at' => now(),
            'last_sync_result' => [
                'success' => empty($errors),
                'queued' => $queued,
                'errors' => $errors,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        return [
            'success' => empty($errors),
            'imported' => 0,
            'queued' => $queued,
            'message' => "Queued {$queued} items.",
            'errors' => $errors,
        ];
    }

    public function queueModelForActiveSites(Model $record, bool $deleted = false): void
    {
        $type = $this->typeFor($record);
        if (!$type) {
            return;
        }

        foreach (SyncSite::active()->cursor() as $site) {
            if ($deleted || $this->legacy->isFilteredOut($record, $site)) {
                if ($deleted || $this->hasStatus($site, $type, $record->id)) {
                    $this->queueDelete($site, $type, $record->id);
                }
                continue;
            }

            $payload = $this->legacy->preparePayload($record, $site, $type);
            $this->queueUpsert($site, $record, $type, $payload, md5(json_encode($payload)));
        }
    }

    public function typeFor(Model $record): ?string
    {
        return match ($record::class) {
            NewYacht::class => 'new_yacht',
            UsedYacht::class => 'used_yacht',
            CharterYacht::class => 'charter_yacht',
            News::class => 'news',
            default => null,
        };
    }

    public function modelClass(string $type): ?string
    {
        return $this->types()[$type] ?? null;
    }

    public function splitPayloadMedia(array $payload, string $type): array
    {
        $fields = [];
        $take = function (string $key, string $fieldType, array $urls) use (&$fields): void {
            $urls = array_values(array_unique(array_filter($urls)));
            $fields[] = compact('key', 'fieldType', 'urls');
        };

        if (array_key_exists('featured_image', $payload)) {
            $take('featured_image', 'featured', [$payload['featured_image']]);
            unset($payload['featured_image']);
        }
        if (array_key_exists('image', $payload)) {
            $take('image', 'image', [$payload['image']]);
            unset($payload['image']);
        }

        $mediaTypes = FormFieldConfiguration::where('entity_type', $type)
            ->get()
            ->mapWithKeys(fn (FormFieldConfiguration $field) => [$field->field_key => $this->legacy->mapInputTypeToACF($field->field_type)])
            ->all();

        foreach ($payload['custom_fields'] ?? [] as $key => $value) {
            $fieldType = $mediaTypes[$key] ?? null;
            if (!in_array($fieldType, ['image', 'file', 'gallery'], true)) {
                continue;
            }

            $take($key, $fieldType, $fieldType === 'gallery' ? (array) $value : [$value]);
            unset($payload['custom_fields'][$key], $payload['custom_fields']['_' . $key]);
        }

        // The old generic media list has no target ACF field. It must not trigger downloads.
        unset($payload['media']);

        return [$payload, $fields];
    }

    private function queueUpsert(SyncSite $site, Model $record, string $type, array $payload, string $hash): void
    {
        $this->storeOperation($site, $type, $record->id, $this->sourceId($type, $record->id), 'upsert', $payload, $hash);

        SyncStatus::updateOrCreate([
            'sync_site_id' => $site->id,
            'model_type' => $type,
            'model_id' => $record->id,
        ], [
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    private function queueDelete(SyncSite $site, string $type, int $modelId): void
    {
        $this->storeOperation($site, $type, $modelId, $this->sourceId($type, $modelId), 'delete');
    }

    private function storeOperation(SyncSite $site, string $type, int $modelId, string $sourceId, string $action, ?array $payload = null, ?string $hash = null): void
    {
        $operation = WordPressSyncOutbox::firstOrNew([
            'sync_site_id' => $site->id,
            'model_type' => $type,
            'model_id' => $modelId,
        ]);
        $operation->fill([
            'source_id' => $sourceId,
            'action' => $action,
            'state' => 'pending',
            'version' => ($operation->exists ? $operation->version : 0) + 1,
            'payload' => $payload,
            'media_cursor' => 0,
            'content_hash' => $hash,
            'last_error' => null,
        ])->save();

        ProcessWordPressSyncOperation::dispatch($operation->id)->onQueue('wordpress-sync')->afterCommit();
    }

    private function queueConfigIfChanged(SyncSite $site, array &$errors): void
    {
        $config = $this->legacy->prepareConfigPayload($site);
        $hash = md5(json_encode($config));
        if ($site->last_config_hash === $hash) {
            return;
        }

        if ($this->legacy->pushToWordPress($site, 'config', $config)) {
            $site->update(['last_config_hash' => $hash]);
            return;
        }

        $errors[] = "Failed to sync field configuration to {$site->name}";
    }

    private function hasStatus(SyncSite $site, string $type, int $id): bool
    {
        return SyncStatus::where('sync_site_id', $site->id)->where('model_type', $type)->where('model_id', $id)->exists();
    }

    private function sourceId(string $type, int $id): string
    {
        return ($type === 'news' ? 'news-' : 'yacht-') . $id;
    }

    private function types(): array
    {
        return [
            'new_yacht' => NewYacht::class,
            'used_yacht' => UsedYacht::class,
            'charter_yacht' => CharterYacht::class,
            'news' => News::class,
        ];
    }
}

<?php

namespace App\Jobs;

use App\Models\SyncStatus;
use App\Models\WordPressSyncOutbox;
use App\Services\QueuedWordPressSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ProcessWordPressSyncOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $operationId)
    {
    }

    public function handle(QueuedWordPressSyncService $sync): void
    {
        $operation = WordPressSyncOutbox::with('site')->find($this->operationId);
        if (!$operation || $operation->state === 'completed') {
            return;
        }

        $site = $operation->site;
        if (!$site || !$site->is_active) {
            return;
        }

        if ($operation->action === 'delete') {
            $this->request($site, 'items', [
                'action' => 'delete',
                'item' => ['id' => $operation->model_id, 'type' => $operation->model_type, 'source_id' => $operation->source_id],
            ], $operation->id . ':delete');

            SyncStatus::where('sync_site_id', $site->id)
                ->where('model_type', $operation->model_type)
                ->where('model_id', $operation->model_id)
                ->delete();
            $operation->update(['state' => 'completed', 'last_error' => null]);
            return;
        }

        [$payload, $media] = $sync->splitPayloadMedia($operation->payload ?? [], $operation->model_type);
        $this->request($site, 'items', ['action' => 'upsert', 'item' => $payload], $operation->id . ':metadata');
        $operation->update(['state' => 'media']);

        for ($index = $operation->media_cursor; $index < count($media); $index++) {
            foreach ($media[$index]['urls'] as $url) {
                $this->request($site, 'media', [
                    'source_id' => $operation->source_id,
                    'field' => $media[$index]['key'],
                    'field_type' => $media[$index]['fieldType'],
                    'source_url' => $url,
                ], $operation->id . ':media:' . $index . ':' . md5($url));
            }
            $operation->update(['media_cursor' => $index + 1]);
        }

        $this->request($site, 'media/finalize', [
            'source_id' => $operation->source_id,
            'fields' => $media,
        ], $operation->id . ':finalize');

        SyncStatus::updateOrCreate([
            'sync_site_id' => $site->id,
            'model_type' => $operation->model_type,
            'model_id' => $operation->model_id,
        ], [
            'status' => 'synced',
            'content_hash' => $operation->content_hash,
            'sync_version' => 2,
            'last_synced_at' => now(),
            'error_message' => null,
        ]);

        $operation->update(['state' => 'completed', 'last_error' => null]);
    }

    public function failed(\Throwable $exception): void
    {
        $operation = WordPressSyncOutbox::find($this->operationId);
        if (!$operation) {
            return;
        }

        $operation->update(['state' => 'failed', 'last_error' => $exception->getMessage()]);
        SyncStatus::where('sync_site_id', $operation->sync_site_id)
            ->where('model_type', $operation->model_type)
            ->where('model_id', $operation->model_id)
            ->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
    }

    private function request($site, string $path, array $payload, string $idempotencyKey): void
    {
        $baseUrl = str_contains($site->url, '/wp-json/')
            ? substr($site->url, 0, strpos($site->url, '/wp-json/'))
            : rtrim($site->url, '/');
        $apiKey = $site->api_key ?: app(\App\Settings\ApiSettings::class)->sync_api_key;

        $response = Http::connectTimeout(10)
            ->timeout(90)
            ->retry([1000, 3000, 8000], throw: false)
            ->withHeaders(['X-API-Key' => $apiKey, 'X-Idempotency-Key' => $idempotencyKey])
            ->post($baseUrl . '/wp-json/atal-sync/v2/' . $path, $payload);

        if (!$response->successful() || !$response->json('success')) {
            throw new \RuntimeException("WordPress sync {$path} failed: {$response->status()} {$response->body()}");
        }
    }
}

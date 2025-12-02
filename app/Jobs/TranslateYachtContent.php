<?php

namespace App\Jobs;

use App\Models\FormFieldConfiguration;
use App\Models\Language;
use App\Models\Yacht;
use App\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateYachtContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 minutes per yacht

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Yacht $yacht,
        public bool $force = false
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationService $translationService): void
    {
        Log::info("Starting translation job for Yacht ID: {$this->yacht->id} ({$this->yacht->name})");

        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        if (!$defaultLanguage) {
            Log::error('No default language found for translation');
            $this->logProgress('Error: No default language found', 'error');
            return;
        }

        $targetLanguages = $languages->where('id', '!=', $defaultLanguage->id);
        $updated = false;

        // Standard translatable fields
        $translatableFields = ['name', 'description'];

        // 1. Translate standard fields
        foreach ($translatableFields as $field) {
            $sourceContent = $this->yacht->getTranslation($field, $defaultLanguage->code, false);

            if (empty($sourceContent)) {
                continue;
            }

            foreach ($targetLanguages as $language) {
                $existingTranslation = $this->yacht->getTranslation($field, $language->code, false);

                if (empty($existingTranslation) || $this->force) {
                    $start = microtime(true);
                    $this->logProgress("Translating {$field} to {$language->name}...", 'processing');
                    Log::info("Translating {$field} for yacht {$this->yacht->id} to {$language->code}");

                    $translatedContent = $translationService->translate(
                        $sourceContent,
                        $language->code,
                        $defaultLanguage->code
                    );

                    if ($translatedContent) {
                        $duration = round(microtime(true) - $start, 2);
                        $this->yacht->setTranslation($field, $language->code, $translatedContent);
                        $updated = true;
                        $this->logProgress("Translated {$field} to {$language->name} ({$duration}s)", 'done');
                    } else {
                        $this->logProgress("Failed to translate {$field} to {$language->name}", 'error');
                    }
                } else {
                    $this->logProgress("Skipping {$field} ({$language->name}) - already exists", 'skipped');
                }
            }
        }

        // 2. Translate custom fields
        $customFields = $this->yacht->custom_fields ?? [];
        $configFields = $this->yacht->type === 'new'
            ? FormFieldConfiguration::forNewYachts()->where('is_multilingual', true)->get()
            : FormFieldConfiguration::forUsedYachts()->where('is_multilingual', true)->get();

        foreach ($configFields as $config) {
            $fieldKey = $config->field_key;
            $fieldLabel = $config->label;

            if (!isset($customFields[$fieldKey]) || !is_array($customFields[$fieldKey])) {
                continue;
            }

            $fieldData = $customFields[$fieldKey];
            $sourceContent = $fieldData[$defaultLanguage->code] ?? null;

            if (empty($sourceContent)) {
                continue;
            }

            foreach ($targetLanguages as $language) {
                $existingTranslation = $fieldData[$language->code] ?? null;

                if (empty($existingTranslation) || $this->force) {
                    $start = microtime(true);
                    $this->logProgress("Translating {$fieldLabel} to {$language->name}...", 'processing');
                    Log::info("Translating custom field {$fieldKey} for yacht {$this->yacht->id} to {$language->code}");

                    $translatedContent = $translationService->translate(
                        $sourceContent,
                        $language->code,
                        $defaultLanguage->code
                    );

                    if ($translatedContent) {
                        $duration = round(microtime(true) - $start, 2);
                        $customFields[$fieldKey][$language->code] = $translatedContent;
                        $updated = true;
                        $this->logProgress("Translated {$fieldLabel} to {$language->name} ({$duration}s)", 'done');
                    } else {
                        $this->logProgress("Failed to translate {$fieldLabel} to {$language->name}", 'error');
                    }
                } else {
                    $this->logProgress("Skipping {$fieldLabel} ({$language->name}) - already exists", 'skipped');
                }
            }
        }

        if ($updated) {
            $this->yacht->custom_fields = $customFields;
            $this->yacht->save();
            Log::info("Translation completed for yacht {$this->yacht->id}");
            $this->logProgress("All translations completed successfully!", 'completed');
        } else {
            $this->logProgress("No new translations needed.", 'completed');
        }
    }

    protected function logProgress(string $message, string $status = 'info'): void
    {
        $key = "translation_progress_{$this->yacht->id}";
        $logs = \Illuminate\Support\Facades\Cache::get($key, []);

        $logs[] = [
            'message' => $message,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ];

        \Illuminate\Support\Facades\Cache::put($key, $logs, 3600);
    }
}

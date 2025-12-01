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
        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        if (!$defaultLanguage) {
            Log::error('No default language found for translation');
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
                    Log::info("Translating {$field} for yacht {$this->yacht->id} to {$language->code}");

                    $translatedContent = $translationService->translate(
                        $sourceContent,
                        $language->code,
                        $defaultLanguage->code
                    );

                    if ($translatedContent) {
                        $this->yacht->setTranslation($field, $language->code, $translatedContent);
                        $updated = true;
                    }
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
                    Log::info("Translating custom field {$fieldKey} for yacht {$this->yacht->id} to {$language->code}");

                    $translatedContent = $translationService->translate(
                        $sourceContent,
                        $language->code,
                        $defaultLanguage->code
                    );

                    if ($translatedContent) {
                        $customFields[$fieldKey][$language->code] = $translatedContent;
                        $updated = true;
                    }
                }
            }
        }

        if ($updated) {
            $this->yacht->custom_fields = $customFields;
            $this->yacht->save();
            Log::info("Translation completed for yacht {$this->yacht->id}");
        }
    }
}

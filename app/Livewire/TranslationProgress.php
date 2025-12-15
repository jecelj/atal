<?php

namespace App\Livewire;

use App\Models\FormFieldConfiguration;
use App\Models\Language;
use App\Models\Yacht;
use App\Services\TranslationService;
use Livewire\Component;

class TranslationProgress extends Component
{
    public $yachtId;
    public $type = 'yacht'; // 'yacht' or 'news'
    public $logs = [];
    public $pendingTranslations = []; // List of languages to process
    public $total = 0;
    public $processed = 0;
    public $isCompleted = false;
    public $isStarted = false;

    public function mount($yachtId, $type = 'yacht')
    {
        \Illuminate\Support\Facades\Log::info('TranslationProgress: Component mounted for yacht ' . $yachtId);
        $this->yachtId = $yachtId;
        $this->type = $type;
        $this->prepareTranslations();
    }

    public function prepareTranslations()
    {
        $record = $this->getRecord();
        if (!$record)
            return;

        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();
        $targetLanguages = $languages->where('id', '!=', $defaultLanguage->id);

        if (!$defaultLanguage) {
            $this->addLog('Error: No default language found', 'error');
            $this->isCompleted = true;
            return;
        }

        // Gather all translatable data by language
        foreach ($targetLanguages as $language) {
            $batchData = [];

            // 1. Standard fields
            $translatableFields = $this->getTranslatableFields();
            foreach ($translatableFields as $field) {
                // Check if target already has content
                $existing = $this->getTranslation($record, $field, $language->code);
                if (!empty($existing)) {
                    // Optionally skip or overwrite. Current logic: skip if exists
                    // But if user clicked "Translate All", they might expect full sync?
                    // Previous logic was: skip if !empty. Let's keep that safely.
                    continue;
                }

                $sourceContent = $this->getTranslation($record, $field, $defaultLanguage->code);
                if (!empty($sourceContent)) {
                    $batchData['standard:' . $field] = $sourceContent;
                }
            }

            // 2. Custom fields
            $customFields = $record->custom_fields ?? [];
            $configFields = $this->getConfigFields($record);

            foreach ($configFields as $config) {
                $fieldKey = $config->field_key;

                if (!isset($customFields[$fieldKey]) || !is_array($customFields[$fieldKey]))
                    continue;

                // Check existing
                $existing = $customFields[$fieldKey][$language->code] ?? null;
                if (!empty($existing)) {
                    continue;
                }

                $sourceContent = $customFields[$fieldKey][$defaultLanguage->code] ?? null;
                if (!empty($sourceContent)) {
                    // Flatten structure for translation service: custom:field_key
                    $batchData['custom:' . $fieldKey] = $sourceContent;
                }
            }

            if (!empty($batchData)) {
                $this->pendingTranslations[] = [
                    'language_code' => $language->code,
                    'language_name' => $language->name,
                    'data' => $batchData
                ];
            } else {
                $this->addLog("Skipping {$language->name} - nothing to translate", 'skipped');
            }
        }

        $this->total = count($this->pendingTranslations);

        if ($this->total === 0) {
            $this->addLog("No new translations needed.", 'completed');
            $this->isCompleted = true;
        }
    }

    protected function getTranslation($record, $field, $locale)
    {
        if (method_exists($record, 'getTranslation')) {
            return $record->getTranslation($field, $locale, false);
        }

        // Manual array handling for News
        $value = $record->$field;
        return is_array($value) ? ($value[$locale] ?? null) : null;
    }

    protected function setTranslation($record, $field, $locale, $value)
    {
        if (method_exists($record, 'setTranslation')) {
            $record->setTranslation($field, $locale, $value);
            return;
        }

        // Manual array handling for News
        $data = $record->$field;
        if (!is_array($data))
            $data = [];
        $data[$locale] = $value;
        $record->$field = $data;
    }

    protected function getRecord()
    {
        if ($this->type === 'news') {
            return \App\Models\News::find($this->yachtId);
        }
        return Yacht::find($this->yachtId);
    }

    protected function getTranslatableFields()
    {
        if ($this->type === 'news') {
            return ['title'];
        }
        return ['name', 'description'];
    }

    protected function getConfigFields($record)
    {
        if ($this->type === 'news') {
            return FormFieldConfiguration::forNews()->where('is_multilingual', true)->get();
        }

        return $record->type === 'new'
            ? FormFieldConfiguration::forNewYachts()->where('is_multilingual', true)->get()
            : FormFieldConfiguration::forUsedYachts()->where('is_multilingual', true)->get();
    }

    public $currentBatch = null;

    public function startTranslation()
    {
        $this->isStarted = true;
    }

    public function prepareNextBatch()
    {
        if (empty($this->pendingTranslations)) {
            $this->isCompleted = true;
            $this->addLog("All translations completed successfully!", 'completed');
            return false;
        }

        $this->currentBatch = array_shift($this->pendingTranslations);
        $fieldCount = count($this->currentBatch['data']);
        $this->addLog("Translating {$fieldCount} fields to {$this->currentBatch['language_name']}...", 'processing');

        return true;
    }

    public function processCurrentBatch()
    {
        if (!$this->currentBatch) {
            return;
        }

        $item = $this->currentBatch;
        $record = $this->getRecord();
        $service = app(TranslationService::class);
        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        try {
            $start = microtime(true);

            // Use new structured translation
            $translatedBatch = $service->translateStructured(
                $item['data'],
                $item['language_code'],
                $defaultLanguage->code
            );

            $duration = round(microtime(true) - $start, 2);

            if ($translatedBatch) {
                // Apply translations back to record
                $customFields = $record->custom_fields ?? [];

                foreach ($translatedBatch as $key => $value) {
                    if (str_starts_with($key, 'standard:')) {
                        $fieldName = substr($key, 9);
                        $this->setTranslation($record, $fieldName, $item['language_code'], $value);
                    } elseif (str_starts_with($key, 'custom:')) {
                        $fieldKey = substr($key, 7);
                        $customFields[$fieldKey][$item['language_code']] = $value;
                    }
                }

                // Save custom fields if modified
                if (!empty($customFields)) {
                    $record->custom_fields = $customFields;
                }

                $record->save();

                $this->addLog("Completed {$item['language_name']} in {$duration}s", 'done');
            } else {
                $this->addLog("Failed to translate batch for {$item['language_name']}", 'error');
            }
        } catch (\Exception $e) {
            $this->addLog("Error translating {$item['language_name']}: " . $e->getMessage(), 'error');
        }

        $this->currentBatch = null;
        $this->processed++;
    }

    protected function addLog($message, $status)
    {
        $this->logs[] = [
            'message' => $message,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function closeAndReload()
    {
        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.translation-progress');
    }
}

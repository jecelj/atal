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
    public $pendingTranslations = [];
    public $total = 0;
    public $processed = 0;
    public $isCompleted = false;
    public $isStarted = false;

    public function mount($yachtId, $type = 'yacht')
    {
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

        // 1. Standard fields
        $translatableFields = $this->getTranslatableFields();
        foreach ($translatableFields as $field) {
            $sourceContent = $this->getTranslation($record, $field, $defaultLanguage->code);
            if (empty($sourceContent))
                continue;

            foreach ($targetLanguages as $language) {
                $existing = $this->getTranslation($record, $field, $language->code);
                if (empty($existing)) {
                    $this->pendingTranslations[] = [
                        'type' => 'standard',
                        'field' => $field,
                        'language_code' => $language->code,
                        'language_name' => $language->name,
                        'source' => $sourceContent
                    ];
                } else {
                    $this->addLog("Skipping {$field} ({$language->name}) - already exists", 'skipped');
                }
            }
        }

        // 2. Custom fields (Only relevant if custom_fields exist)
        $customFields = $record->custom_fields ?? [];
        $configFields = $this->getConfigFields($record);

        foreach ($configFields as $config) {
            $fieldKey = $config->field_key;
            $fieldLabel = $config->label;

            if (!isset($customFields[$fieldKey]) || !is_array($customFields[$fieldKey]))
                continue;

            $sourceContent = $customFields[$fieldKey][$defaultLanguage->code] ?? null;
            if (empty($sourceContent))
                continue;

            foreach ($targetLanguages as $language) {
                $existing = $customFields[$fieldKey][$language->code] ?? null;
                if (empty($existing)) {
                    $this->pendingTranslations[] = [
                        'type' => 'custom',
                        'field' => $fieldKey,
                        'label' => $fieldLabel,
                        'language_code' => $language->code,
                        'language_name' => $language->name,
                        'source' => $sourceContent
                    ];
                } else {
                    $this->addLog("Skipping {$fieldLabel} ({$language->name}) - already exists", 'skipped');
                }
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

    public function startTranslation()
    {
        $this->isStarted = true;
    }

    public function translateNext()
    {
        if (empty($this->pendingTranslations)) {
            $this->isCompleted = true;
            $this->addLog("All translations completed successfully!", 'completed');
            return;
        }

        $item = array_shift($this->pendingTranslations);
        $record = $this->getRecord();
        $service = app(TranslationService::class);
        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        $fieldName = $item['type'] === 'standard' ? $item['field'] : ($item['label'] ?? $item['field']);

        try {
            $start = microtime(true);
            $translated = $service->translate(
                $item['source'],
                $item['language_code'],
                $defaultLanguage->code
            );
            $duration = round(microtime(true) - $start, 2);

            if ($translated) {
                if ($item['type'] === 'standard') {
                    $this->setTranslation($record, $item['field'], $item['language_code'], $translated);
                    $record->save();
                } else {
                    $customFields = $record->custom_fields ?? [];
                    $customFields[$item['field']][$item['language_code']] = $translated;
                    $record->custom_fields = $customFields;
                    $record->save();
                }

                $this->addLog("Translated {$fieldName} to {$item['language_name']} ({$duration}s)", 'done');
            } else {
                $this->addLog("Failed to translate {$fieldName} to {$item['language_name']}", 'error');
            }
        } catch (\Exception $e) {
            $this->addLog("Error translating {$fieldName}: " . $e->getMessage(), 'error');
        }

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

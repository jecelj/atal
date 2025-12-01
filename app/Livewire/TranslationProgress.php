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
    public $logs = [];
    public $pendingTranslations = [];
    public $total = 0;
    public $processed = 0;
    public $isCompleted = false;
    public $isStarted = false;

    public function mount($yachtId)
    {
        $this->yachtId = $yachtId;
        $this->prepareTranslations();
    }

    public function prepareTranslations()
    {
        $yacht = Yacht::find($this->yachtId);
        if (!$yacht)
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
        $translatableFields = ['name', 'description'];
        foreach ($translatableFields as $field) {
            $sourceContent = $yacht->getTranslation($field, $defaultLanguage->code, false);
            if (empty($sourceContent))
                continue;

            foreach ($targetLanguages as $language) {
                $existing = $yacht->getTranslation($field, $language->code, false);
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

        // 2. Custom fields
        $customFields = $yacht->custom_fields ?? [];
        $configFields = $yacht->type === 'new'
            ? FormFieldConfiguration::forNewYachts()->where('is_multilingual', true)->get()
            : FormFieldConfiguration::forUsedYachts()->where('is_multilingual', true)->get();

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
        $yacht = Yacht::find($this->yachtId);
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
                    $yacht->setTranslation($item['field'], $item['language_code'], $translated);
                    $yacht->save();
                } else {
                    $customFields = $yacht->custom_fields ?? [];
                    $customFields[$item['field']][$item['language_code']] = $translated;
                    $yacht->custom_fields = $customFields;
                    $yacht->save();
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

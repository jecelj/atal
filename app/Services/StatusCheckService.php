<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Yacht;
use App\Models\News;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class StatusCheckService
{
    protected $languages;
    protected $defaultLanguage;

    public function __construct()
    {
        $this->languages = Language::all();
        $this->defaultLanguage = $this->languages->where('is_default', true)->first();
    }

    /**
     * Check and update status for a record
     */
    public function checkAndUpdateStatus(Model $record): void
    {
        $imgStatus = $this->checkImageOptimization($record);
        $transStatus = $this->checkTranslations($record);

        $record->update([
            'img_opt_status' => $imgStatus,
            'translation_status' => $transStatus,
        ]);
    }

    /**
     * Check if all images are optimized (WebP and < 500KB)
     */
    public function checkImageOptimization(Model $record): bool
    {
        $mediaItems = $record->getMedia('*');

        if ($mediaItems->isEmpty()) {
            return true; // No images = optimized (or at least not un-optimized)
        }

        foreach ($mediaItems as $media) {
            // Skip non-images
            if (!str_starts_with($media->mime_type, 'image/')) {
                continue;
            }

            $isValid = true;

            // Check format (must be WebP)
            if ($media->mime_type !== 'image/webp') {
                $isValid = false;
            }

            // Check size (must be < 1MB = 1048576 bytes)
            if ($media->size > 1048576) {
                $isValid = false;
            }

            if (!$isValid) {
                // If invalid, we MUST clear the optimized flag so it gets picked up again
                if ($media->getCustomProperty('optimized')) {
                    $media->forgetCustomProperty('optimized');
                    $media->save();
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Check if translations exist for all active languages
     */
    public function checkTranslations(Model $record): bool
    {
        if (!$this->defaultLanguage) {
            return false; // Configuration error
        }

        $activeLanguages = $this->languages->where('is_default', false);
        $defaultCode = $this->defaultLanguage->code;

        // 1. Check Standard Translatable Fields
        $translatableFields = $record->translatable ?? [];

        // For News, title/content/excerpt are cast to array but handled differently in model
        if ($record instanceof News) {
            $translatableFields = ['title', 'content', 'excerpt'];
        }

        foreach ($translatableFields as $field) {
            // Get value in default language
            if (method_exists($record, 'getTranslation')) {
                $defaultValue = $record->getTranslation($field, $defaultCode, false);
            } else {
                // Handle array-cast fields (like in News model)
                $fieldValue = $record->$field;
                $defaultValue = $fieldValue[$defaultCode] ?? null;
            }

            if (!empty($defaultValue)) {
                // If default has value, ALL other languages must have value
                foreach ($activeLanguages as $lang) {
                    if (method_exists($record, 'getTranslation')) {
                        $transValue = $record->getTranslation($field, $lang->code, false);
                    } else {
                        $fieldValue = $record->$field;
                        $transValue = $fieldValue[$lang->code] ?? null;
                    }

                    if (empty($transValue)) {
                        return false;
                    }
                }
            }
        }

        // 2. Check Custom Fields (Multilingual)
        // We need to know which custom fields are multilingual.
        // We can fetch configurations based on model type.
        $configurations = collect();
        if ($record instanceof \App\Models\NewYacht) {
            $configurations = \App\Models\FormFieldConfiguration::forNewYachts()->get();
        } elseif ($record instanceof \App\Models\UsedYacht) {
            $configurations = \App\Models\FormFieldConfiguration::forUsedYachts()->get();
        } elseif ($record instanceof News) {
            $configurations = \App\Models\FormFieldConfiguration::forNews()->get();
        }

        $customFields = $record->custom_fields ?? [];

        foreach ($configurations as $config) {
            if ($config->is_multilingual) {
                $fieldKey = $config->field_key;

                // Check if default language has value in custom_fields
                // Structure: custom_fields['field_key']['en']
                $defaultValue = $customFields[$fieldKey][$defaultCode] ?? null;

                if (!empty($defaultValue)) {
                    foreach ($activeLanguages as $lang) {
                        $transValue = $customFields[$fieldKey][$lang->code] ?? null;
                        if (empty($transValue)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }
}

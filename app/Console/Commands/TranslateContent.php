<?php

namespace App\Console\Commands;

use App\Models\FormFieldConfiguration;
use App\Models\Language;
use App\Models\Yacht;
use App\Services\TranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TranslateContent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atal:translate-content {--force : Force re-translation of existing content}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate missing content for yachts using OpenAI';

    /**
     * Execute the console command.
     */
    public function handle(TranslationService $translationService)
    {
        $this->info('Starting content translation...');

        $languages = Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        if (!$defaultLanguage) {
            $this->error('No default language found!');
            return;
        }

        $targetLanguages = $languages->where('id', '!=', $defaultLanguage->id);
        $yachts = Yacht::all();
        $bar = $this->output->createProgressBar($yachts->count());

        // Standard translatable fields
        $translatableFields = ['name', 'description', 'specifications'];

        // Get custom field configurations
        $newYachtFields = FormFieldConfiguration::forNewYachts()
            ->where('is_multilingual', true)
            ->get();

        $usedYachtFields = FormFieldConfiguration::forUsedYachts()
            ->where('is_multilingual', true)
            ->get();

        foreach ($yachts as $yacht) {
            $updated = false;

            // 1. Translate standard fields
            foreach ($translatableFields as $field) {
                // Get content in default language
                $sourceContent = $yacht->getTranslation($field, $defaultLanguage->code, false);

                if (empty($sourceContent)) {
                    continue;
                }

                foreach ($targetLanguages as $language) {
                    // Check if translation exists
                    $existingTranslation = $yacht->getTranslation($field, $language->code, false);

                    if (empty($existingTranslation) || $this->option('force')) {
                        $this->line(" Translating {$field} for yacht {$yacht->id} to {$language->code}...");

                        $translatedContent = $translationService->translate(
                            $sourceContent,
                            $language->code,
                            $defaultLanguage->code
                        );

                        if ($translatedContent) {
                            $yacht->setTranslation($field, $language->code, $translatedContent);
                            $updated = true;
                        }
                    }
                }
            }

            // 2. Translate custom fields
            $customFields = $yacht->custom_fields ?? [];
            $configFields = $yacht->type === 'new' ? $newYachtFields : $usedYachtFields;

            foreach ($configFields as $config) {
                $fieldKey = $config->field_key;

                // Check if field exists in custom_fields
                if (!isset($customFields[$fieldKey])) {
                    continue;
                }

                $fieldData = $customFields[$fieldKey];

                // Ensure field data is array (it should be for multilingual)
                if (!is_array($fieldData)) {
                    continue;
                }

                // Get source content
                $sourceContent = $fieldData[$defaultLanguage->code] ?? null;

                if (empty($sourceContent)) {
                    continue;
                }

                foreach ($targetLanguages as $language) {
                    $existingTranslation = $fieldData[$language->code] ?? null;

                    if (empty($existingTranslation) || $this->option('force')) {
                        $this->line(" Translating custom field {$fieldKey} for yacht {$yacht->id} to {$language->code}...");

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
                $yacht->custom_fields = $customFields;
                $yacht->save();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Translation completed!');
    }
}

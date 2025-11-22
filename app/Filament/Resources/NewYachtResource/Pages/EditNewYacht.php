<?php

namespace App\Filament\Resources\NewYachtResource\Pages;

use App\Filament\Resources\NewYachtResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewYacht extends EditRecord
{
    protected static string $resource = NewYachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('translateAll')
                ->label('Translate All')
                ->icon('heroicon-m-language')
                ->color('info')
                ->requiresConfirmation(function () {
                    // Check if any multilingual fields already have content
                    $data = $this->form->getState();
                    $configurations = \App\Models\FormFieldConfiguration::forNewYachts()
                        ->where('is_multilingual', true)
                        ->get();

                    $languages = \App\Models\Language::where('is_default', false)->get();

                    foreach ($configurations as $config) {
                        foreach ($languages as $language) {
                            $value = data_get($data, "custom_fields.{$config->field_key}.{$language->code}");
                            if (!empty($value)) {
                                return true; // Require confirmation
                            }
                        }
                    }

                    return false; // No confirmation needed
                })
                ->modalHeading('Translate all fields?')
                ->modalDescription('Some fields already contain translations. Do you want to overwrite them with automatic translations?')
                ->modalSubmitActionLabel('Yes, translate all')
                ->action(function () {
                    $data = $this->form->getState();
                    $translationService = app(\App\Services\TranslationService::class);

                    $configurations = \App\Models\FormFieldConfiguration::forNewYachts()
                        ->where('is_multilingual', true)
                        ->whereIn('field_type', ['text', 'textarea', 'richtext'])
                        ->get();

                    $defaultLanguage = \App\Models\Language::where('is_default', true)->first();
                    $targetLanguages = \App\Models\Language::where('is_default', false)->get();

                    if (!$defaultLanguage || $targetLanguages->isEmpty()) {
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('No languages configured')
                            ->body('Please configure languages in the system.')
                            ->send();
                        return;
                    }

                    $translatedCount = 0;
                    $skippedCount = 0;

                    foreach ($configurations as $config) {
                        $sourceText = data_get($data, "custom_fields.{$config->field_key}.{$defaultLanguage->code}");

                        if (empty($sourceText)) {
                            $skippedCount++;
                            continue;
                        }

                        foreach ($targetLanguages as $language) {
                            try {
                                $translated = $translationService->translate(
                                    $sourceText,
                                    $language->code,
                                    $defaultLanguage->code
                                );

                                if ($translated) {
                                    data_set($data, "custom_fields.{$config->field_key}.{$language->code}", $translated);
                                    $translatedCount++;
                                }
                            } catch (\Exception $e) {
                                // Continue with other fields
                            }
                        }
                    }

                    // Update form state
                    $this->form->fill($data);

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Translation completed')
                        ->body("Translated {$translatedCount} fields. Skipped {$skippedCount} empty fields.")
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}

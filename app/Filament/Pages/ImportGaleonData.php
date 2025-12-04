<?php

namespace App\Filament\Pages;

use App\Services\GaleonMigrationService;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Log;

class ImportGaleonData extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Import Used Yachts';

    protected static ?string $navigationGroup = 'Migration';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.import-galeon-data';

    public $fieldsJson;
    public $yachtJson;
    public $bulkJson;

    public function importFields()
    {
        $this->validate([
            'fieldsJson' => 'required|string',
        ]);

        try {
            $data = json_decode($this->fieldsJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format');
            }

            // Extract fields array if wrapped
            $fields = $data['fields'] ?? $data;

            if (!is_array($fields)) {
                throw new \Exception('Invalid fields structure');
            }

            // Delete existing Used Yacht fields
            \App\Models\FormFieldConfiguration::forUsedYachts()->delete();

            // Create new fields
            $imported = 0;
            foreach ($fields as $field) {
                // Skip Brand as it is a native relation. Model is kept as text field.
                if ($field['field_key'] === 'brand') {
                    continue;
                }

                // Force multilingual for richtext fields
                $isMultilingual = $field['is_multilingual'] ?? false;
                if ($field['field_type'] === 'richtext') {
                    $isMultilingual = true;
                }

                \App\Models\FormFieldConfiguration::create([
                    'entity_type' => 'used_yacht',
                    'group' => $field['group'] ?? null,
                    'field_key' => $field['field_key'],
                    'field_type' => $field['field_type'],
                    'label' => $field['label'],
                    'is_required' => $field['is_required'] ?? false,
                    'is_multilingual' => $isMultilingual,
                    'order' => $field['order'] ?? 0,
                    'options' => $field['options'] ?? null,
                    'validation_rules' => $field['validation_rules'] ?? null,
                ]);
                $imported++;
            }

            Notification::make()
                ->title('Fields Imported')
                ->body("Successfully imported {$imported} field configurations.")
                ->success()
                ->send();

            $this->fieldsJson = '';

        } catch (\Exception $e) {
            Notification::make()
                ->title('Import Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function importSingleYacht()
    {
        $this->validate([
            'yachtJson' => 'required|string',
        ]);

        try {
            $data = json_decode($this->yachtJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format');
            }

            $service = app(GaleonMigrationService::class);
            $result = $service->importUsedYacht($data);

            if ($result['success']) {
                Notification::make()
                    ->title('Yacht Imported')
                    ->body("Successfully imported '{$result['yacht_name']}'")
                    ->success()
                    ->send();

                $this->yachtJson = '';
            } else {
                throw new \Exception($result['error']);
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Import Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function importBulkYachts()
    {
        $this->validate([
            'bulkJson' => 'required|string',
        ]);

        try {
            $data = json_decode($this->bulkJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format');
            }

            // Check if it's a list of yachts
            if (!is_array($data)) {
                throw new \Exception('Invalid data structure (expected array of yachts)');
            }

            // Disable WebP conversion to prevent timeouts
            \App\Listeners\ConvertImageToWebP::$shouldConvert = false;

            $service = app(GaleonMigrationService::class);
            $successCount = 0;
            $failCount = 0;

            foreach ($data as $yachtData) {
                $result = $service->importUsedYacht($yachtData);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    Log::error("Bulk import failed for one item", ['error' => $result['error']]);
                }
            }

            Notification::make()
                ->title('Bulk Import Completed')
                ->body("Imported: {$successCount}, Failed: {$failCount}")
                ->success()
                ->send();

            $this->bulkJson = '';

        } catch (\Exception $e) {
            Notification::make()
                ->title('Import Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            // Always re-enable WebP conversion
            \App\Listeners\ConvertImageToWebP::$shouldConvert = true;
        }
    }
}

<?php

namespace App\Filament\Pages;

use App\Services\GaleonMigrationService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class ImportGaleonBulk extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?string $navigationGroup = 'Migration';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Bulk Import Yachts';

    protected static string $view = 'filament.pages.import-galeon-bulk';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('json_data')
                    ->label('Bulk Yachts JSON Data')
                    ->placeholder('Paste the bulk JSON export from WordPress here...')
                    ->rows(20)
                    ->required()
                    ->helperText('Copy the JSON array output from WordPress Galeon Migration "Export All Yachts" and paste it here.')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function importAll()
    {
        $data = $this->form->getState();

        try {
            // Decode JSON
            $yachtsData = json_decode($data['json_data'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Notification::make()
                    ->title('Invalid JSON')
                    ->body('The provided JSON is not valid: ' . json_last_error_msg())
                    ->danger()
                    ->send();
                return;
            }

            if (!is_array($yachtsData)) {
                Notification::make()
                    ->title('Invalid Format')
                    ->body('Expected an array of yachts')
                    ->danger()
                    ->send();
                return;
            }

            // Import all yachts
            $service = app(GaleonMigrationService::class);
            $total = count($yachtsData);
            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($yachtsData as $yachtData) {
                try {
                    $result = $service->importYacht($yachtData);

                    if ($result['success']) {
                        $success++;
                        Log::info("Imported yacht: {$result['yacht_name']} (ID: {$result['yacht_id']})");
                    } else {
                        $failed++;
                        $errors[] = ($yachtData['name'] ?? 'Unknown') . ': ' . ($result['error'] ?? 'Unknown error');
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = ($yachtData['name'] ?? 'Unknown') . ': ' . $e->getMessage();
                    Log::error("Failed to import yacht: " . $e->getMessage());
                }
            }

            // Show summary notification
            if ($failed === 0) {
                Notification::make()
                    ->title('Bulk Import Successful!')
                    ->body("Successfully imported all {$success} yachts.")
                    ->success()
                    ->send();
            } else {
                $message = "Imported {$success} of {$total} yachts. {$failed} failed.";
                if (!empty($errors)) {
                    $message .= "\n\nErrors:\n" . implode("\n", array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $message .= "\n... and " . (count($errors) - 5) . " more errors.";
                    }
                }

                Notification::make()
                    ->title('Bulk Import Completed with Errors')
                    ->body($message)
                    ->warning()
                    ->send();
            }

            // Clear the form
            $this->form->fill(['json_data' => '']);

            // Redirect to yachts list
            $this->redirect(route('filament.admin.resources.new-yachts.index'));

        } catch (\Exception $e) {
            Notification::make()
                ->title('Import Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}

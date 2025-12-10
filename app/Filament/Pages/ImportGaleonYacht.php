<?php

namespace App\Filament\Pages;

use App\Services\GaleonMigrationService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class ImportGaleonYacht extends Page implements HasForms
{
    use InteractsWithForms;
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Import New Yacht (Single)';

    protected static ?string $navigationGroup = 'Migration';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.import-galeon-yacht';

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
                    ->label('Yacht JSON Data')
                    ->placeholder('Paste the JSON export from WordPress here...')
                    ->rows(20)
                    ->required()
                    ->helperText('Copy the JSON output from WordPress Galeon Migration plugin and paste it here.')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function import()
    {
        $data = $this->form->getState();

        try {
            // Decode JSON
            $yachtData = json_decode($data['json_data'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Notification::make()
                    ->title('Invalid JSON')
                    ->body('The provided JSON is not valid: ' . json_last_error_msg())
                    ->danger()
                    ->send();
                return;
            }

            // Import yacht
            $service = app(GaleonMigrationService::class);
            $result = $service->importYacht($yachtData);

            if ($result['success']) {
                Notification::make()
                    ->title('Import Successful!')
                    ->body("Yacht '{$result['yacht_name']}' (ID: {$result['yacht_id']}) has been imported successfully.")
                    ->success()
                    ->send();

                // Clear the form
                $this->form->fill(['json_data' => '']);

                // Redirect to the yacht edit page
                $this->redirect(route('filament.admin.resources.new-yachts.edit', ['record' => $result['yacht_id']]));
            } else {
                Notification::make()
                    ->title('Import Failed')
                    ->body($result['error'] ?? 'Unknown error occurred')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Import Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}

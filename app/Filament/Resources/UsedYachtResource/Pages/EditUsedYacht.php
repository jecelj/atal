<?php

namespace App\Filament\Resources\UsedYachtResource\Pages;

use App\Filament\Resources\UsedYachtResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUsedYacht extends EditRecord
{
    protected static string $resource = UsedYachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('translateAll')
                ->label('Translate All')
                ->icon('heroicon-m-language')
                ->color('info')
                ->action(function () {
                    $this->save();
                    $record = $this->getRecord();

                    // Open modal via widget
                    $this->dispatch('open-translation-modal', yachtId: $record->id);
                }),
            Actions\Action::make('optimizeImages')
                ->label('Optimize Images')
                ->icon('heroicon-m-photo')
                ->color('warning')
                ->action(function () {
                    $this->save();
                    $record = $this->getRecord();

                    try {
                        $service = app(\App\Services\ImageOptimizationService::class);
                        $stats = $service->processYachtImages($record);

                        $message = "Images processed: {$stats['processed']}";
                        if ($stats['renamed'] > 0)
                            $message .= ", Renamed: {$stats['renamed']}";
                        if ($stats['converted'] > 0)
                            $message .= ", Converted: {$stats['converted']}";
                        if ($stats['resized'] > 0)
                            $message .= ", Resized: {$stats['resized']}";
                        if ($stats['errors'] > 0)
                            $message .= ", Errors: {$stats['errors']}";

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Optimization Complete')
                            ->body($message)
                            ->send();

                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('Optimization Failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\TranslationProgressWidget::class,
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Actions\Action::make('saveAndPublish')
                ->label('Save and Publish')
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->action(function () {
                    $this->form->getState();
                    $this->data['state'] = 'published';
                    $this->save();

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Yacht published successfully')
                        ->send();
                }),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        // Run image optimization
        try {
            $service = app(\App\Services\ImageOptimizationService::class);
            $stats = $service->processYachtImages($record);

            if ($stats['processed'] > 0) {
                $message = "Images processed: {$stats['processed']}";
                if ($stats['renamed'] > 0)
                    $message .= ", Renamed: {$stats['renamed']}";
                if ($stats['converted'] > 0)
                    $message .= ", Converted to WebP: {$stats['converted']}";
                if ($stats['resized'] > 0)
                    $message .= ", Resized: {$stats['resized']}";

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title('Image Optimization Complete')
                    ->body($message)
                    ->send();
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->warning()
                ->title('Image Optimization Warning')
                ->body('Some images could not be processed: ' . $e->getMessage())
                ->send();
        }
    }
}

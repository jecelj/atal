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
                    // Save the record first to ensure all images are in DB
                    $this->save();

                    $record = $this->getRecord();

                    // Open modal via widget
                    $this->dispatch('open-optimization-modal', recordId: $record->id, type: 'yacht');
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\TranslationProgressWidget::class,
            \App\Filament\Widgets\ImageOptimizationProgressWidget::class,
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


}

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
                    // Just get the current record
                    $record = $this->getRecord();

                    // Open modal via widget
                    $this->dispatch('open-optimization-modal', recordId: $record->id, type: 'used_yacht');
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

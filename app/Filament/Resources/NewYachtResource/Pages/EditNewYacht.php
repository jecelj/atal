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
                    $this->dispatch('open-translation-modal', yachtId: $record->id);
                }),
            Actions\Action::make('optimizeImages')
                ->label('Optimize Images')
                ->icon('heroicon-m-photo')
                ->color('warning')
                ->action(function () {
                    $this->save();
                    $record = $this->getRecord();
                    $this->dispatch('open-optimization-modal', recordId: $record->id, type: 'yacht');
                }),
            Actions\Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->url($this->getResource()::getUrl('index')),
            Actions\Action::make('save')
                ->label('Save')
                ->action('save')
                ->keyBindings(['mod+s']),
            Actions\Action::make('saveAndExit')
                ->label('Save & Exit')
                ->color('success')
                ->action(function () {
                    $this->save();
                    return redirect($this->getResource()::getUrl('index'));
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
        return [];
    }


}

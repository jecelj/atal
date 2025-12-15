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
                ->action(function () {
                    \Illuminate\Support\Facades\Log::info('Translate All clicked');

                    // Save without redirecting to keep the Livewire context/events alive
                    $this->save(shouldRedirect: false);

                    $record = $this->getRecord();
                    \Illuminate\Support\Facades\Log::info('Dispatching open-translation-modal for yacht: ' . $record->id);
                    $this->dispatch('open-translation-modal', yachtId: $record->id)
                        ->to(\App\Filament\Widgets\TranslationProgressWidget::class);
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
            Actions\DeleteAction::make(),
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

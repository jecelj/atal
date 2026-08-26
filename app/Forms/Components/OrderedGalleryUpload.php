<?php

namespace App\Forms\Components;

use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

class OrderedGalleryUpload extends SpatieMediaLibraryFileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        // FilePond otherwise uploads multiple files in parallel. The visual order can
        // then differ from the order in which Livewire receives the uploads.
        $this
            ->appendFiles()
            ->maxParallelUploads(1)
            ->reorderable()
            ->extraAlpineAttributes([
                'x-on:refresh-gallery-page.window' => 'window.location.reload()',
            ])
            ->helperText('The displayed order is saved. Drag images to fine-tune it; Reverse order saves the gallery and refreshes this page.')
            ->hintAction(
                Action::make('reverseOrder')
                    ->label('Reverse order')
                    ->icon('heroicon-m-arrows-up-down')
                    ->action(function (Forms\Set $set, $state, $component, $livewire): void {
                        if (! $component instanceof Component || ! is_array($state) || count($state) < 2) {
                            return;
                        }

                        // Preserve the UUID/file keys: their insertion order controls
                        // both the FilePond preview and Spatie's order_column values.
                        $set($component, array_reverse($state, true));

                        // On edit pages save only this gallery, then reload so FilePond
                        // is rebuilt from the newly persisted media order.
                        if (method_exists($livewire, 'saveFormComponentOnly')) {
                            $livewire->saveFormComponentOnly($component);
                            $livewire->dispatch('refresh-gallery-page');
                        }
                    }),
            );
    }
}

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
            ->helperText('Drag images to fine-tune their order. After reversing, click Save to store the gallery together with all other form changes.')
            ->hintAction(
                Action::make('reverseOrder')
                    ->label('Reverse order')
                    ->icon('heroicon-m-arrows-up-down')
                    ->action(function (Forms\Set $set, $state, $component): void {
                        if (! $component instanceof Component || ! is_array($state) || count($state) < 2) {
                            return;
                        }

                        // Preserve the UUID/file keys: their insertion order controls
                        // both the FilePond preview and Spatie's order_column values.
                        $set($component, array_reverse($state, true));

                        // Do not call EditRecord::saveFormComponentOnly() here. This
                        // gallery is nested in the custom_fields JSON column; saving
                        // just this component overwrites price, year, and other
                        // unsaved custom fields with an incomplete JSON payload.
                    }),
            );
    }
}

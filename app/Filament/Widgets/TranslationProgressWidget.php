<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class TranslationProgressWidget extends Widget
{
    protected static string $view = 'filament.widgets.translation-progress-widget';

    public ?int $yachtId = null;

    #[On('open-translation-modal')]
    public function openModal(int $yachtId)
    {
        $this->yachtId = $yachtId;
        $this->dispatch('open-modal', id: 'translation-progress');
    }
}

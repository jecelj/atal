<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class TranslationProgressWidget extends Widget
{
    protected static string $view = 'filament.widgets.translation-progress-widget';

    public ?int $yachtId = null;
    public string $type = 'yacht';

    #[On('open-translation-modal')]
    public function openModal(int $yachtId, string $type = 'yacht')
    {
        $this->yachtId = $yachtId;
        $this->type = $type;
        $this->dispatch('open-modal', id: 'translation-progress');
    }
}

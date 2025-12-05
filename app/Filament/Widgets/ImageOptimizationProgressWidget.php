<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class ImageOptimizationProgressWidget extends Widget
{
    protected static string $view = 'filament.widgets.image-optimization-progress-widget';

    public ?int $recordId = null;
    public string $type = 'yacht'; // 'yacht', 'news', 'used_yacht'

    #[On('open-optimization-modal')]
    public function openModal(int $recordId, string $type = 'yacht')
    {
        $this->recordId = $recordId;
        $this->type = $type;
        $this->dispatch('open-modal', id: 'image-optimization-progress');
    }
}

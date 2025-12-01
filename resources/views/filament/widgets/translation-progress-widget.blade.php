<x-filament::widget>
    <x-filament::modal id="translation-progress" width="lg" :close-by-clicking-away="false">
        @if($yachtId)
            @livewire('translation-progress', ['yachtId' => $yachtId])
        @endif
    </x-filament::modal>
</x-filament::widget>
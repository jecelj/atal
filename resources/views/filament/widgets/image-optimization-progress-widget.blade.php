<x-filament::widget>
    <x-filament::modal id="image-optimization-progress" width="lg" :close-by-clicking-away="false">
        @if($recordId)
            @livewire('image-optimization-progress', ['recordId' => $recordId, 'type' => $type ?? 'yacht'])
        @endif
    </x-filament::modal>
</x-filament::widget>
<x-filament-panels::page>
    <style>
        .gallery-checkbox-list .fi-fo-checkbox-list-option-label {
            display: flex;
            flex-direction: column-reverse;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            /* Optional: border for card look */
            border-radius: 0.5rem;
            height: 100%;
        }

        .gallery-checkbox-list img {
            max-width: 100%;
            height: auto;
        }
    </style>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="flex gap-3 mt-6">
            <x-filament::button type="submit">
                Import Yacht
            </x-filament::button>
            <x-filament::button color="gray" tag="a" href="{{ route('filament.admin.resources.new-yachts.index') }}">
                Cancel
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
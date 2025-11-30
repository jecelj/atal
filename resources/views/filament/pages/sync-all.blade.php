<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Full Synchronization
            </x-slot>

            <x-slot name="description">
                Sync EVERYTHING (New Yachts, Used Yachts, News) to all active WordPress sites.
                This process may take a while.
            </x-slot>

            <div class="flex gap-4">
                <x-filament::button wire:click="syncAll" color="danger" size="lg"
                    icon="heroicon-o-arrow-path-rounded-square">
                    SYNC EVERYTHING
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Sync All Sites --}}
        <x-filament::section>
            <x-slot name="heading">
                Sync All News
            </x-slot>

            <x-slot name="description">
                Sync all published news to their assigned WordPress sites.
            </x-slot>

            <div class="flex gap-4">
                <x-filament::button wire:click="syncAllSites" color="primary" icon="heroicon-o-arrow-path">
                    Sync All News
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- Sync Individual Sites --}}
        <x-filament::section>
            <x-slot name="heading">
                Sync Individual Sites
            </x-slot>

            <x-slot name="description">
                Sync all news assigned to a specific site.
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($this->getSites() as $site)
                    <x-filament::card>
                        <div class="flex flex-col gap-2">
                            <div class="font-semibold">{{ $site->name }}</div>
                            <div class="text-sm text-gray-500">{{ $site->url }}</div>
                            <div class="flex gap-2 mt-2">
                                <x-filament::button wire:click="syncToSite({{ $site->id }})" size="sm" color="success">
                                    Sync News
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::card>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-filament::section>
            <div class="flex items-center gap-4">
                <x-filament::icon icon="heroicon-o-server-stack" class="w-8 h-8 text-gray-400" />
                <div>
                    <h2 class="text-lg font-bold">{{ $stats['total'] }}</h2>
                    <p class="text-sm text-gray-500">Total Tracked Items</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex items-center gap-4">
                <x-filament::icon icon="heroicon-o-check-circle" class="w-8 h-8 text-success-500" />
                <div>
                    <h2 class="text-lg font-bold text-success-600">{{ $stats['synced'] }}</h2>
                    <p class="text-sm text-gray-500">Synced</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex items-center gap-4">
                <x-filament::icon icon="heroicon-o-x-circle" class="w-8 h-8 text-danger-500" />
                <div>
                    <h2 class="text-lg font-bold text-danger-600">{{ $stats['failed'] }}</h2>
                    <p class="text-sm text-gray-500">Failed</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex items-center gap-4">
                <x-filament::icon icon="heroicon-o-clock" class="w-8 h-8 text-warning-500" />
                <div>
                    <h2 class="text-lg font-bold text-warning-600">{{ $stats['pending'] }}</h2>
                    <p class="text-sm text-gray-500">Pending / Dirty</p>
                </div>
            </div>
        </x-filament::section>
    </div>

    <div class="space-y-6">
        @foreach($sites as $site)
            <x-filament::section collapsible collapsed="{{ !$site->is_active }}">
                <x-slot name="heading">
                    <div class="flex items-center justify-between w-full">
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-bold">{{ $site->name }}</span>
                            <x-filament::badge color="{{ $site->is_active ? 'success' : 'gray' }}">
                                {{ $site->is_active ? 'Active' : 'Inactive' }}
                            </x-filament::badge>
                            @if($site->last_synced_at)
                                <span class="text-sm text-gray-500 ml-2">
                                    Last synced: {{ $site->last_synced_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-sm text-gray-400 ml-2">Never synced</span>
                            @endif
                        </div>
                    </div>
                </x-slot>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-bold mb-2">Configuration</h3>
                        <ul class="text-sm space-y-1 text-gray-600">
                            <li><strong>URL:</strong> {{ $site->url }}</li>
                            <li><strong>Default Language:</strong> {{ $site->default_language }}</li>
                            <li><strong>Supported Languages:</strong> {{ implode(', ', $site->supported_languages ?? []) }}
                            </li>
                            <li><strong>Sync All Brands:</strong> {{ $site->sync_all_brands ? 'Yes' : 'No' }}</li>
                            @if(!$site->sync_all_brands)
                                <li><strong>Restricted Brands:</strong> {{ count($site->brand_restrictions ?? []) }} rules</li>
                            @endif
                        </ul>

                        <div class="mt-4">
                            <x-filament::button icon="heroicon-o-arrow-path" size="sm"
                                wire:click="syncSite({{ $site->id }})" tag="a" href="#" onclick="return false;" {{--
                                Placeholder for livewire action if we were using it, but page is simple view --}}>
                                {{-- Since we can't easily wire actions in simple Pages without Livewire logic in
                                controller,
                                we might need to move logic.
                                Actually, Filament Pages are Livewire components!
                                So we CAN add public function syncSite($id) to the Controller. --}}
                                Trigger Manual Sync
                            </x-filament::button>
                        </div>
                    </div>

                    <div>
                        <h3 class="font-bold mb-2">Last Sync Result</h3>
                        @if($site->last_sync_result)
                            <div class="bg-gray-50 p-2 rounded text-xs font-mono overflow-auto max-h-40">
                                @if(isset($site->last_sync_result['success']) && $site->last_sync_result['success'])
                                    <span class="text-success-600">SUCCESS</span><br>
                                    Imported: {{ $site->last_sync_result['imported'] ?? 0 }}<br>
                                    Timestamp: {{ $site->last_sync_result['timestamp'] ?? '' }}
                                @else
                                    <span class="text-danger-600">FAILED</span><br>
                                    Error:
                                    {{ is_array($site->last_sync_result['errors'] ?? null) ? implode(', ', $site->last_sync_result['errors']) : ($site->last_sync_result['error'] ?? 'Unknown') }}
                                @endif
                            </div>
                        @else
                            <p class="text-sm text-gray-400">No recent sync data.</p>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
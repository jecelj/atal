<div class="flex gap-1">
    @php
        $sites = \App\Models\SyncSite::where('is_active', true)->orderBy('order')->get();
        // Determine model class and type for lookup
        $record = $getRecord();
        $modelType = match (get_class($record)) {
            'App\Models\NewYacht' => 'new_yacht',
            'App\Models\UsedYacht' => 'used_yacht',
            'App\Models\News' => 'news',
            default => null
        };
    @endphp

    @if($modelType)
        @foreach($sites as $site)
            @php
                $status = \App\Models\SyncStatus::where('sync_site_id', $site->id)
                    ->where('model_type', $modelType)
                    ->where('model_id', $record->id)
                    ->first();

                $color = 'gray'; // Pending/Null
                $icon = 'heroicon-o-minus-circle';
                $tooltip = "{$site->name}: Not synced";

                if ($status) {
                    if ($status->status === 'synced') {
                        $color = 'success';
                        $icon = 'heroicon-o-check-circle';
                        $tooltip = "{$site->name}: Synced " . ($status->last_synced_at ? $status->last_synced_at->diffForHumans() : '');
                    } elseif ($status->status === 'failed') {
                        $color = 'danger';
                        $icon = 'heroicon-o-x-circle';
                        $tooltip = "{$site->name}: Failed - " . Str::limit($status->error_message ?? 'Unknown error', 50);
                    }
                }
            @endphp

            <div title="{{ $tooltip }}">
                <x-filament::icon :icon="$icon" class="h-5 w-5 text-{{ $color }}-500" />
            </div>
        @endforeach
    @endif
</div>
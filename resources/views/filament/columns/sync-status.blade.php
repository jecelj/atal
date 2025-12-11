<div class="flex gap-1 items-center justify-start">
    @php
        $sites = \App\Models\SyncSite::where('is_active', true)->orderBy('order')->get();
        $record = $getRecord();
        $modelType = match (get_class($record)) {
            'App\Models\NewYacht' => 'new_yacht',
            'App\Models\UsedYacht' => 'used_yacht',
            'App\Models\News' => 'news',
            default => null
        };

        // Determine record publication state
        $isPublished = false;
        if (isset($record->state)) {
            $isPublished = $record->state === 'published';
        } elseif (isset($record->is_active)) {
            $isPublished = $record->is_active; // For News
        }
    @endphp

    @if($modelType)
        @foreach($sites as $site)
            @php
                $status = \App\Models\SyncStatus::where('sync_site_id', $site->id)
                    ->where('model_type', $modelType)
                    ->where('model_id', $record->id)
                    ->first();

                // Default state (unknown/pending)
                $syncState = $status ? $status->status : 'pending';

                // Logic based on User Request
                if (!$isPublished) {
                    // Unpublished
                    if ($syncState === 'pending') {
                        // Pending Sync (Needs to be removed from WP) -> Orange Warning
                        $colorStyle = 'color: #ea580c;'; // orange-600
                        $icon = 'heroicon-o-exclamation-triangle';
                        $tooltip = "{$site->name}: Pending Unpublish (Needs Sync)";
                    } else {
                        // Synced (Deleted) or Null -> Gray Minus
                        $colorStyle = 'color: #9ca3af;'; // gray-400
                        $icon = 'heroicon-o-minus-circle';
                        $tooltip = "{$site->name}: Not Published (Skipped)";
                    }
                } else {
                    // Published -> Check Sync Status
                    if ($syncState === 'synced') {
                        // Published & Synced -> Green Check
                        $colorStyle = 'color: #16a34a;'; // green-600
                        $icon = 'heroicon-o-check-circle';
                        $tooltip = "{$site->name}: Synced " . ($status?->last_synced_at ? $status->last_synced_at->diffForHumans() : '');
                    } elseif ($syncState === 'failed') {
                        // Published & Failed -> Red X
                        $colorStyle = 'color: #dc2626;'; // red-600
                        $icon = 'heroicon-o-x-circle';
                        $tooltip = "{$site->name}: Failed - " . Str::limit($status->error_message ?? 'Unknown error', 50);
                    } else {
                        // Published & Pending/Null -> Orange Warning
                        $colorStyle = 'color: #ea580c;'; // orange-600
                        $icon = 'heroicon-o-exclamation-triangle';
                        $tooltip = "{$site->name}: Pending Sync";
                    }
                }
            @endphp

            <div title="{{ $tooltip }}">
                <x-filament::icon :icon="$icon" class="h-6 w-6" style="{{ $colorStyle }}" />
            </div>
        @endforeach
    @endif
</div>
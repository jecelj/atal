<div class="flex gap-1">
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
                        $color = 'warning';
                        $icon = 'heroicon-o-exclamation-triangle';
                        $tooltip = "{$site->name}: Pending Unpublish (Needs Sync)";
                    } else {
                        // Synced (Deleted) or Null -> Gray Minus
                        $color = 'gray';
                        $icon = 'heroicon-o-minus-circle';
                        $tooltip = "{$site->name}: Not Published (Skipped)";
                    }
                } else {
                    // Published -> Check Sync Status
                    if ($syncState === 'synced') {
                        // Published & Synced -> Green Check
                        $color = 'success';
                        $icon = 'heroicon-o-check-circle';
                        $tooltip = "{$site->name}: Synced " . ($status?->last_synced_at ? $status->last_synced_at->diffForHumans() : '');
                    } elseif ($syncState === 'failed') {
                        // Published & Failed -> Red X (User said "rahlo oranžen klicaj" for "not synced", but failed is distinct)
                        // I will use Red for Failed to distinguish from Pending.
                        $color = 'danger';
                        $icon = 'heroicon-o-x-circle';
                        $tooltip = "{$site->name}: Failed - " . Str::limit($status->error_message ?? 'Unknown error', 50);
                    } else {
                        // Published & Pending/Null -> Orange Warning ("rahlo oranžen klicaj")
                        $color = 'warning';
                        $icon = 'heroicon-o-exclamation-triangle';
                        $tooltip = "{$site->name}: Pending Sync";
                    }
                }
            @endphp

            <div title="{{ $tooltip }}">
                <x-filament::icon :icon="$icon" class="h-5 w-5 text-{{ $color }}-500" />
            </div>
        @endforeach
    @endif
</div>
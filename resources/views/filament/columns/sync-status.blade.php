@php
    $record = $getRecord();
    $isUsedYacht = $record instanceof \App\Models\UsedYacht;
    $sites = \App\Models\SyncSite::where('is_active', true)->orderBy('order')->get();
    $assignedSiteIds = $isUsedYacht
        ? $record->syncSites()->pluck('sync_sites.id')->map(fn ($id) => (string) $id)->all()
        : [];

    $modelType = match (get_class($record)) {
        'App\Models\NewYacht' => 'new_yacht',
        'App\Models\UsedYacht' => 'used_yacht',
        'App\Models\CharterYacht' => 'charter_yacht',
        'App\Models\News' => 'news',
        default => null,
    };

    $statuses = $modelType
        ? \App\Models\SyncStatus::query()
            ->whereIn('sync_site_id', $sites->modelKeys())
            ->where('model_type', $modelType)
            ->where('model_id', $record->id)
            ->get()
            ->keyBy('sync_site_id')
        : collect();

    // Determine record publication state
    $isPublished = false;
    if (isset($record->state)) {
        $isPublished = $record->state === 'published';
    } elseif (isset($record->is_active)) {
        $isPublished = $record->is_active; // For News
    }
@endphp

<div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
    @if($modelType)
        @foreach($sites as $site)
            @php
                $status = $statuses->get($site->id);
                $syncState = $status?->status ?? 'pending';
                $isAssigned = in_array((string) $site->getKey(), $assignedSiteIds, true);

                if ($isUsedYacht) {
                    // All active targets remain visible. A gray cross means that the
                    // target is disabled or waiting for its first sync.
                    if (!$isAssigned) {
                        $colorStyle = 'color: rgb(var(--gray-400));';
                        $icon = 'heroicon-o-x-mark';
                        $tooltip = "{$site->name}: Sync disabled — click to enable";
                    } elseif ($isPublished && $syncState === 'synced') {
                        $colorStyle = 'color: rgb(var(--success-500));';
                        $icon = 'heroicon-o-check-circle';
                        $tooltip = "{$site->name}: Synced " . ($status?->last_synced_at ? $status->last_synced_at->diffForHumans() : '');
                    } elseif ($syncState === 'failed') {
                        $colorStyle = 'color: rgb(var(--danger-600));';
                        $icon = 'heroicon-o-x-circle';
                        $tooltip = "{$site->name}: Failed - " . \Illuminate\Support\Str::limit($status->error_message ?? 'Unknown error', 50);
                    } else {
                        $colorStyle = 'color: rgb(var(--gray-400));';
                        $icon = 'heroicon-o-x-mark';
                        $tooltip = $isPublished
                            ? "{$site->name}: Not synced yet"
                            : "{$site->name}: Not published";
                    }
                } elseif (!$isPublished) {
                    // Unpublished records retain the existing status behavior for the other resources.
                    if ($status && $status->status === 'pending') {
                        $colorStyle = 'color: rgb(var(--warning-500));';
                        $icon = 'heroicon-o-exclamation-triangle';
                        $tooltip = "{$site->name}: Pending Unpublish (Needs Sync)";
                    } else {
                        $colorStyle = 'color: rgb(var(--gray-400));';
                        $icon = 'heroicon-o-minus-circle';
                        $tooltip = "{$site->name}: Not Published (Skipped)";
                    }
                } elseif ($syncState === 'synced') {
                    $colorStyle = 'color: rgb(var(--success-500));';
                    $icon = 'heroicon-o-check-circle';
                    $tooltip = "{$site->name}: Synced " . ($status?->last_synced_at ? $status->last_synced_at->diffForHumans() : '');
                } elseif ($syncState === 'skipped') {
                    $colorStyle = 'color: rgb(var(--gray-400));';
                    $icon = 'heroicon-o-minus-circle';
                    $tooltip = "{$site->name}: Skipped (Filtered)";
                } elseif ($syncState === 'failed') {
                    $colorStyle = 'color: rgb(var(--danger-600));';
                    $icon = 'heroicon-o-x-circle';
                    $tooltip = "{$site->name}: Failed - " . \Illuminate\Support\Str::limit($status->error_message ?? 'Unknown error', 50);
                } else {
                    $colorStyle = 'color: rgb(var(--warning-500));';
                    $icon = 'heroicon-o-exclamation-triangle';
                    $tooltip = "{$site->name}: Pending Sync";
                }
            @endphp

            @if ($isUsedYacht)
                <button
                    type="button"
                    wire:click.stop="toggleSyncSite({{ $record->getKey() }}, {{ $site->getKey() }})"
                    x-on:click.stop
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                    wire:target="toggleSyncSite({{ $record->getKey() }}, {{ $site->getKey() }})"
                    class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-sm transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-wait"
                    title="{{ $tooltip }}"
                    aria-label="{{ $isAssigned ? "Disable sync to {$site->name}" : "Enable sync to {$site->name}" }}"
                >
                    <x-filament::icon :icon="$icon" class="h-5 w-5 shrink-0" style="{{ $colorStyle }}" />
                    <span class="whitespace-nowrap">{{ $site->name }}</span>
                </button>
            @else
                <span class="inline-flex items-center gap-1 text-sm" title="{{ $tooltip }}">
                    <x-filament::icon :icon="$icon" class="h-5 w-5 shrink-0" style="{{ $colorStyle }}" />
                </span>
            @endif
        @endforeach
    @endif
</div>

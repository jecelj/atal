<span
    x-data="{ isProcessing: @js($isProcessing) }"
    x-on:sync-navigation-status-changed.window="isProcessing = $event.detail.isProcessing"
    class="block h-full w-full"
>
    <x-filament::icon
        icon="heroicon-o-arrow-path"
        class="h-full w-full animate-spin text-primary-500"
        x-cloak
        x-show="isProcessing"
    />
    <x-filament::icon
        icon="heroicon-o-arrow-path-rounded-square"
        class="h-full w-full"
        x-cloak
        x-show="! isProcessing"
    />
</span>

<div wire:poll.2s="refreshLogs" class="p-4">
    <h3 class="text-lg font-bold mb-4">Translation Progress</h3>

    <div class="space-y-2 max-h-96 overflow-y-auto border rounded p-2 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
        @if(empty($logs))
            <div class="text-gray-500 italic">Starting translation...</div>
        @endif

        @foreach($logs as $log)
            <div class="flex items-center justify-between text-sm border-b pb-1 last:border-0 dark:border-gray-700">
                <span
                    class="{{ $log['status'] === 'error' ? 'text-danger-600' : ($log['status'] === 'skipped' ? 'text-gray-500' : 'text-gray-700 dark:text-gray-300') }}">
                    {{ $log['message'] }}
                </span>
                <span class="text-xs text-gray-400">
                    {{ \Carbon\Carbon::parse($log['timestamp'])->format('H:i:s') }}
                </span>
            </div>
        @endforeach
    </div>

    @if($isCompleted)
        <div class="mt-4 flex justify-end">
            <x-filament::button wire:click="closeAndReload" color="success">
                Done & Reload
            </x-filament::button>
        </div>
    @else
        <div class="mt-4 flex items-center gap-2 text-primary-600">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>Translating...</span>
        </div>
    @endif
</div>
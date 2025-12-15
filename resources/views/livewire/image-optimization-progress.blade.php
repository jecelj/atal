<div x-data="{
        init() {
            $dispatch('open-modal', { id: 'image-optimization-progress' });
            if (@js(!$isStarted) && @js(!$isCompleted)) {
                $wire.startOptimization();
            }
        }
    }" class="p-4">
    <h3 class="text-lg font-bold mb-4">Image Optimization</h3>

    <div class="space-y-2 max-h-96 overflow-y-auto border rounded p-2 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
        @if(empty($logs))
            <div class="text-gray-500 italic">Starting optimization...</div>
        @endif

        @foreach($logs as $log)
            <div class="flex items-center justify-between text-sm border-b pb-1 last:border-0 dark:border-gray-700">
                <span
                    class="{{ $log['status'] === 'error' ? 'text-danger-600' : ($log['status'] === 'warning' ? 'text-warning-600' : ($log['status'] === 'done' ? 'text-success-600' : ($log['status'] === 'completed' ? 'text-success-700 font-bold' : 'text-gray-700 dark:text-gray-300'))) }}">
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
            <span>Optimizing images... please wait.</span>
        </div>
    @endif
</div>
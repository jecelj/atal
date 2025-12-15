<div x-data="{
        init() {
            if (@js(!$isStarted) && @js(!$isCompleted)) {
                $wire.startTranslation();
                this.processNext();
            }
        },
        processNext() {
            if (@js(!$isCompleted)) {
                $wire.prepareNextBatch().then(hasMore => {
                    if (hasMore) {
                        // Allow UI to update with 'currentBatch' info before starting heavy work
                        setTimeout(() => {
                            $wire.processCurrentBatch().then(() => {
                                if (!@js($isCompleted)) {
                                    this.processNext();
                                }
                            });
                        }, 100); 
                    }
                });
            }
        }
    }" class="p-4">
    <h3 class="text-lg font-bold mb-4">Translation Progress</h3>

    <!-- Progress Bar -->
    @if($total > 0)
        <div class="mb-4">
            <div class="flex justify-between text-sm mb-1">
                <span>Progress</span>
                <span>{{ $processed }} / {{ $total }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="bg-primary-600 h-2.5 rounded-full transition-all duration-500"
                    style="width: {{ ($processed / $total) * 100 }}%"></div>
            </div>

            @if($currentBatch)
                <div class="mt-2 text-sm text-primary-600 font-medium animate-pulse">
                    Currently translating: {{ $currentBatch['language_name'] }}...
                </div>
            @endif
        </div>
    @endif

    <div class="space-y-2 max-h-96 overflow-y-auto border rounded p-2 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
        @if(empty($logs))
            <div class="text-gray-500 italic">Preparing translations...</div>
        @endif

        @foreach($logs as $log)
            <div class="flex items-center justify-between text-sm border-b pb-1 last:border-0 dark:border-gray-700">
                <span
                    class="{{ $log['status'] === 'error' ? 'text-danger-600' : ($log['status'] === 'skipped' ? 'text-gray-500' : ($log['status'] === 'done' ? 'text-success-600' : ($log['status'] === 'processing' ? 'text-info-600' : 'text-gray-700 dark:text-gray-300'))) }}">
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
            <span>Processing...</span>
        </div>
    @endif
</div>
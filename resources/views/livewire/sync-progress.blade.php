<div class="space-y-4">
    @if(!$completed)
        <div>
            <div class="mb-2 flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    @if($currentSite)
                        Syncing: <strong>{{ $currentSite }}</strong>
                    @else
                        Preparing...
                    @endif
                </span>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ number_format($progress, 0) }}%
                </span>
            </div>

            <!-- Progress bar -->
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ $progress }}%">
                </div>
            </div>
        </div>

        <!-- Auto-start sync on mount -->
        <div wire:init="startSync"></div>

        <!-- Spinner -->
        <div class="flex justify-center py-4">
            <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none"
                viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
        </div>
    @else
        <div class="space-y-3">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Sync Complete! 🎉
            </h3>

            @foreach($results as $result)
                <div
                    class="flex items-start gap-3 p-3 rounded-lg {{ $result['success'] ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20' }}">
                    @if($result['success'])
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400 mt-0.5" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    @else
                        <svg class="w-5 h-5 text-red-600 dark:text-red-400 mt-0.5" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    @endif

                    <div class="flex-1">
                        <p class="font-medium text-gray-900 dark:text-white">{{ $result['site'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $result['message'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
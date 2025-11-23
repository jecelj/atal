<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="h-full bg-gray-50 dark:bg-gray-900">
    <div class="min-h-full flex items-center justify-center px-4">
        <div class="w-full max-w-2xl">
            <!-- Header -->
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
                    {{ $title }}
                </h1>
                <p class="mt-2 text-gray-600 dark:text-gray-400">
                    Please wait while we sync your data...
                </p>
            </div>

            <!-- Progress Component -->
            <div class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-8">
                @livewire('sync-progress', ['siteId' => $siteId])
            </div>

            <!-- Back button (shown after completion) -->
            <div class="mt-6 text-center">
                <a href="{{ url('/admin/sync-sites') }}"
                    class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Sync Sites
                </a>
            </div>
        </div>
    </div>

    @livewireScripts
</body>

</html>
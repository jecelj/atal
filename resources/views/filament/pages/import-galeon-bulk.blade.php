<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Bulk Import Yachts from Galeon Adriatic
        </x-slot>

        <x-slot name="description">
            Export all yachts from WordPress using the Galeon Migration plugin, then paste the JSON array here to import
            them all at once.
        </x-slot>

        <form wire:submit="importAll">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" size="lg">
                    <x-heroicon-o-arrow-down-on-square-stack class="w-5 h-5 mr-2" />
                    Import All Yachts
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section class="mt-6">
        <x-slot name="heading">
            Instructions
        </x-slot>

        <div class="prose dark:prose-invert max-w-none">
            <ol>
                <li>Go to <strong>WordPress Admin → Galeon Migration</strong> on galeonadriatic.com</li>
                <li>Click <strong>"Export All Yachts"</strong> button</li>
                <li>Wait for the export to complete (this may take a minute)</li>
                <li>Click <strong>"📋 Copy All Yachts JSON to Clipboard"</strong></li>
                <li>Paste it into the textarea above</li>
                <li>Click <strong>"Import All Yachts"</strong></li>
                <li>Wait for the import to complete (this may take several minutes)</li>
            </ol>

            <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <p class="text-sm text-blue-800 dark:text-blue-200 mb-0">
                    <strong>Note:</strong> The bulk import will automatically download all images and media files for
                    each yacht. This process may take 5-10 minutes depending on the number of yachts and images.
                </p>
            </div>

            <div class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                <p class="text-sm text-yellow-800 dark:text-yellow-200 mb-0">
                    <strong>Categories included:</strong> Explorer, Flybridge, GTO, Hardtop, Skydeck<br>
                    <strong>Categories excluded:</strong> Preowned Yachts, Uncategorized
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
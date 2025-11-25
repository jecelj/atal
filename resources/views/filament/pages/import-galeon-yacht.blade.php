<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Import Yacht from Galeon Adriatic
        </x-slot>

        <x-slot name="description">
            Export a yacht from WordPress using the Galeon Migration plugin, then paste the JSON here to import it into
            the master system.
        </x-slot>

        <form wire:submit="import">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" size="lg">
                    <x-heroicon-o-arrow-down-tray class="w-5 h-5 mr-2" />
                    Import Yacht
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
                <li>Enter the <strong>Post ID</strong> of the yacht you want to export</li>
                <li>Click <strong>"Export Single Yacht"</strong></li>
                <li>Copy the entire JSON output</li>
                <li>Paste it into the textarea above</li>
                <li>Click <strong>"Import Yacht"</strong></li>
            </ol>

            <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <p class="text-sm text-blue-800 dark:text-blue-200 mb-0">
                    <strong>Note:</strong> The import will automatically download all images and media files from the
                    WordPress site. This may take a few minutes depending on the number of images.
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
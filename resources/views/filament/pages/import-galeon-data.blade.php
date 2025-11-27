<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Import Fields --}}
        <x-filament::section>
            <x-slot name="heading">
                1. Import Field Configuration
            </x-slot>
            <x-slot name="description">
                Paste the JSON content from <code>Export Field Configuration</code> here.
            </x-slot>

            <form wire:submit="importFields" class="space-y-4">
                <div>
                    <textarea wire:model="fieldsJson" rows="5"
                        class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500"
                        placeholder="Paste JSON here..."></textarea>
                    @error('fieldsJson') <span class="text-danger-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Import Fields
                </x-filament::button>

                <div wire:loading wire:target="importFields" class="text-sm text-gray-500">
                    Importing...
                </div>
            </form>
        </x-filament::section>

        {{-- Import Single Yacht --}}
        <x-filament::section>
            <x-slot name="heading">
                2. Test Import (Single Yacht)
            </x-slot>
            <x-slot name="description">
                Paste the JSON content from <code>Export Used Yacht</code> here.
            </x-slot>

            <form wire:submit="importSingleYacht" class="space-y-4">
                <div>
                    <textarea wire:model="yachtJson" rows="5"
                        class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500"
                        placeholder="Paste JSON here..."></textarea>
                    @error('yachtJson') <span class="text-danger-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Import Single Yacht
                </x-filament::button>

                <div wire:loading wire:target="importSingleYacht" class="text-sm text-gray-500">
                    Importing...
                </div>
            </form>
        </x-filament::section>

        {{-- Bulk Import --}}
        <x-filament::section>
            <x-slot name="heading">
                3. Bulk Import (All Yachts)
            </x-slot>
            <x-slot name="description">
                Paste the JSON content from <code>Export All Used Yachts</code> here.
            </x-slot>

            <form wire:submit="importBulkYachts" class="space-y-4">
                <div>
                    <textarea wire:model="bulkJson" rows="10"
                        class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500"
                        placeholder="Paste JSON here..."></textarea>
                    @error('bulkJson') <span class="text-danger-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <x-filament::button type="submit" color="success" wire:loading.attr="disabled">
                    Import All Yachts
                </x-filament::button>

                <div wire:loading wire:target="importBulkYachts" class="text-sm text-gray-500">
                    Importing (this may take a while)...
                </div>
            </form>
        </x-filament::section>

    </div>
</x-filament-panels::page>
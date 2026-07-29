@php
    $price = $getState();
    $recordKey = $getRecord()->getKey();
@endphp

<div
    x-data="{
        error: null,
        isEditing: false,
        isSaving: false,
        originalPrice: @js($price),
        price: @js($price),
        formatPrice(value) {
            if (value === null || value === '') {
                return '-'
            }

            return new Intl.NumberFormat('sl-SI', {
                currency: 'EUR',
                maximumFractionDigits: 0,
                style: 'currency',
            }).format(Number(value))
        },
        startEditing() {
            this.originalPrice = this.price
            this.error = null
            this.isEditing = true

            this.$nextTick(() => this.$refs.input.focus())
        },
        cancel() {
            this.error = null
            this.price = this.originalPrice
            this.isEditing = false
        },
        async save() {
            if (this.isSaving) {
                return
            }

            this.isSaving = true
            this.error = null

            try {
                const response = await $wire.savePrice(@js($recordKey), this.price)

                if (response.error) {
                    this.error = response.error
                    return
                }

                this.price = response.price
                this.originalPrice = response.price
                this.isEditing = false
            } catch {
                this.error = 'Price could not be saved.'
            } finally {
                this.isSaving = false
            }
        },
    }"
    class="min-w-44"
    x-on:keydown.escape.stop="cancel()"
>
    <div x-show="! isEditing" class="flex items-center gap-1">
        <span class="min-w-28" x-text="formatPrice(price)"></span>

        <button
            type="button"
            title="Edit price"
            aria-label="Edit price"
            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-500 dark:hover:text-primary-400"
            x-on:click.stop="startEditing()"
        >
            <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
        </button>
    </div>

    <div x-show="isEditing" x-cloak class="flex items-center gap-1">
        <input
            x-ref="input"
            x-model="price"
            type="number"
            min="0"
            step="1"
            inputmode="numeric"
            class="block h-8 w-28 rounded-lg border-gray-300 py-1 text-sm text-gray-950 focus:border-primary-600 focus:ring-primary-600 dark:border-gray-600 dark:bg-white/5 dark:text-white"
            x-bind:disabled="isSaving"
            x-on:click.stop
            x-on:keydown.enter.prevent="save()"
        />

        <button
            type="button"
            title="Save price"
            aria-label="Save price"
            class="flex h-8 w-8 items-center justify-center rounded-lg text-success-600 transition hover:text-success-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-success-600 disabled:opacity-50 dark:text-success-400"
            x-bind:disabled="isSaving"
            x-on:click.stop="save()"
        >
            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
        </button>

        <button
            type="button"
            title="Cancel editing"
            aria-label="Cancel editing"
            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 disabled:opacity-50 dark:text-gray-500 dark:hover:text-gray-300"
            x-bind:disabled="isSaving"
            x-on:click.stop="cancel()"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
        </button>
    </div>

    <p x-show="error" x-cloak class="mt-1 text-xs text-danger-600 dark:text-danger-400" x-text="error"></p>
</div>

<div x-data="{
        images: $wire.entangle('data.custom_fields.all_images'),
        coverImage: $wire.entangle('data.custom_fields.cover_image_url'),
        gridImage: $wire.entangle('data.custom_fields.grid_image_url'),
        gridHoverImage: $wire.entangle('data.custom_fields.grid_image_hover_url'),
        
        setCover(url) {
            this.coverImage = [url];
        },
        setCover(url) {
            this.coverImage = [url];
        },
        setGrid(url) {
            this.gridImage = [url];
            // Force Livewire update if entangle is stuck
            $wire.set('data.custom_fields.grid_image_url', [url]);
        },
        setGridHover(url) {
            this.gridHoverImage = [url];
            $wire.set('data.custom_fields.grid_image_hover_url', [url]);
        },
        isCover(url) {
            if (Array.isArray(this.coverImage)) return this.coverImage[0] == url;
            return this.coverImage == url;
        },
        isGrid(url) {
            if (Array.isArray(this.gridImage)) return this.gridImage[0] == url;
            return this.gridImage == url;
        },
        isGridHover(url) {
            if (Array.isArray(this.gridHoverImage)) return this.gridHoverImage[0] == url;
            return this.gridHoverImage == url;
        },
        categories: [
            { id: 'gallery_exterior', label: 'Exterior' },
            { id: 'gallery_interior', label: 'Interior' },
            { id: 'gallery_cockpit', label: 'Cockpit' },
            { id: 'gallery_layout', label: 'Layout' },
            { id: 'trash', label: 'Trash (Ignore)' }
        ]
    }" class="space-y-4">
    <!-- Header / Info -->
    <div
        class="flex justify-between items-center p-4 bg-gray-50 rounded-lg border border-gray-200 dark:bg-gray-900 dark:border-gray-700">
        <div>
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200">Media Manager</h3>
            <p class="text-sm text-gray-500">Categorize images and select main visuals.</p>
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-400">
            <span x-text="images ? images.length : 0"></span> images found
        </div>
    </div>

    <!-- Image Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <template x-for="(image, index) in images" :key="index">
            <div class="relative group bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden flex flex-col"
                :class="{ 
                    'opacity-50 grayscale': image.category === 'trash', 
                    'ring-4 ring-indigo-500': isGridHover(image.url),
                    'ring-4 ring-blue-500': !isGridHover(image.url) && isGrid(image.url),
                    'ring-4 ring-green-500': !isGridHover(image.url) && !isGrid(image.url) && isCover(image.url)
                }">
                <!-- Image Preview -->
                <div class="relative aspect-video bg-gray-100 dark:bg-gray-900 cursor-zoom-in"
                    @click="$dispatch('open-lightbox', { url: image.url })">
                    <img :src="image.url" class="w-full h-full object-cover" loading="lazy">

                    <!-- Badges -->
                    <div class="absolute top-2 left-2 flex flex-col gap-1">
                        <template x-show="isCover(image.url)">
                            <span
                                class="px-2 py-1 text-xs font-bold text-white bg-green-600 rounded shadow">COVER</span>
                        </template>
                        <template x-show="isGrid(image.url)">
                            <span class="px-2 py-1 text-xs font-bold text-white bg-blue-600 rounded shadow">GRID</span>
                        </template>
                        <template x-show="isGridHover(image.url)">
                            <span
                                class="px-2 py-1 text-xs font-bold text-white bg-indigo-600 rounded shadow">HOVER</span>
                        </template>
                    </div>
                </div>

                <!-- Controls -->
                <div class="p-3 space-y-3 flex-1 flex flex-col justify-between">

                    <!-- Category Selector -->
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                        <select x-model="image.category"
                            class="w-full text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 cursor-pointer">
                            <option value="gallery_exterior">Exterior</option>
                            <option value="gallery_interior">Interior</option>
                            <option value="gallery_cockpit">Cockpit</option>
                            <option value="gallery_layout">Layout</option>
                            <option value="trash">Trash (Ignore)</option>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100 dark:border-gray-700"
                        x-show="image.category !== 'trash'">
                        <!-- Debug Button State -->
                        <!-- <div class="w-full text-[10px] text-gray-400" x-text="'Grid: ' + (isGrid(image.url) ? 'YES' : 'NO')"></div> -->
                        <button type="button" @click="setCover(image.url)"
                            class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                            :class="isCover(image.url) ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:border-green-800' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'">
                            Cover
                        </button>
                        <button type="button" @click="setGrid(image.url)"
                            class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                            :class="isGrid(image.url) ? 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:border-blue-800' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'">
                            Grid
                        </button>
                        <button type="button" @click="setGridHover(image.url)"
                            class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                            :class="isGridHover(image.url) ? 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-900/30 dark:border-indigo-800' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'">
                            Hover
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
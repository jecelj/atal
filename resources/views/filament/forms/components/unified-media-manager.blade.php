@php
    $categories = $categories ?? [
        'gallery_exterior' => 'Exterior',
        'gallery_interior' => 'Interior',
        'gallery_cockpit' => 'Cockpit',
        'gallery_layout' => 'Layout',
        'trash' => 'Trash (Ignore)',
    ];
    // Normalize categories to array of objects if simple array
    $normalizedCategories = [];
    foreach ($categories as $id => $label) {
        $normalizedCategories[] = ['id' => $id, 'label' => $label];
    }

    // Default buttons configuration
    $allowedButtons = $buttons ?? ['cover', 'grid', 'hover'];
@endphp

<div x-data="{
        images: $wire.entangle('data.custom_fields.all_images'),
        coverImage: $wire.entangle('data.custom_fields.cover_image_url'),
        gridImage: $wire.entangle('data.custom_fields.grid_image_url'),
        gridHoverImage: $wire.entangle('data.custom_fields.grid_image_hover_url'),
        
        // Dynamic setter handling
        setImageType(url, type) {
            if (type === 'cover') this.coverImage = [url];
            if (type === 'grid') {
                 this.gridImage = [url];
                 $wire.set('data.custom_fields.grid_image_url', [url]);
            }
            if (type === 'hover') {
                 this.gridHoverImage = [url];
                 $wire.set('data.custom_fields.grid_image_hover_url', [url]);
            }
            if (type === 'image_1') {
                // Special handler for Used Yacht 'image_1' button
                // We can map 'image_1' button to setting the category of the image to 'image_1'
                // OR we can map it to a specific field. 
                // The user request says: 'Grid image = key:image_1'
                // For Used Yacht, we probably just want to mark it visually.
                // But the save logic relies on 'category'.
                // So if we click 'Main Image', it should set that image's category to 'image_1'.
                
                // Reset other image_1
                this.images = this.images.map(img => {
                    if (img.category === 'image_1') img.category = 'galerie';
                    if (img.url === url) img.category = 'image_1';
                    return img;
                });
            }
        },

        setImageCategory(index, category) {
            this.images[index].category = category;
        },
        
        isType(url, type) {
            if (type === 'cover') return (Array.isArray(this.coverImage) ? this.coverImage[0] : this.coverImage) == url;
            if (type === 'grid') return (Array.isArray(this.gridImage) ? this.gridImage[0] : this.gridImage) == url;
            if (type === 'hover') return (Array.isArray(this.gridHoverImage) ? this.gridHoverImage[0] : this.gridHoverImage) == url;
            if (type === 'image_1') {
                 let img = this.images.find(i => i.url === url);
                 return img && img.category === 'image_1';
            }
            return false;
        },

        isCategory(index, category) {
            return this.images[index] && this.images[index].category === category;
        },

        categories: {{ Js::from($normalizedCategories) }}
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
                    'opacity-50 grayscale': image.category === 'trash'
                }">
                <!-- Image Preview -->
                <div class="relative aspect-video bg-gray-100 dark:bg-gray-900 cursor-zoom-in"
                    @click="$dispatch('open-lightbox', { url: image.url })">
                    <img :src="image.url" class="w-full h-full object-cover" loading="lazy">

                    <!-- Badges -->
                    <div class="absolute top-2 left-2 flex flex-col gap-1">
                        @if(in_array('cover', $allowedButtons))
                            <template x-show="isType(image.url, 'cover')">
                                <span
                                    class="px-2 py-1 text-xs font-bold text-white bg-green-600 rounded shadow">COVER</span>
                            </template>
                        @endif
                        @if(in_array('grid', $allowedButtons))
                            <template x-show="isType(image.url, 'grid')">
                                <span class="px-2 py-1 text-xs font-bold text-white bg-blue-600 rounded shadow">GRID</span>
                            </template>
                        @endif
                        @if(in_array('hover', $allowedButtons))
                            <template x-show="isType(image.url, 'hover')">
                                <span
                                    class="px-2 py-1 text-xs font-bold text-white bg-indigo-600 rounded shadow">HOVER</span>
                            </template>
                        @endif
                        @if(in_array('image_1', $allowedButtons))
                            <template x-show="isType(image.url, 'image_1')">
                                <span class="px-2 py-1 text-xs font-bold text-white bg-blue-600 rounded shadow">MAIN</span>
                            </template>
                        @endif
                    </div>
                </div>

                <!-- Controls -->
                <div class="p-3 space-y-3 flex-1 flex flex-col justify-between">

                    <!-- Category Buttons -->
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach($normalizedCategories as $cat)
                                @if($cat['id'] !== 'trash')
                                    <button type="button" @click="setImageCategory(index, '{{ $cat['id'] }}')"
                                        title="{{ $cat['label'] }}"
                                        class="px-2 py-1 text-xs font-medium rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                        :class="isCategory(index, '{{ $cat['id'] }}') ? 'text-white' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'"
                                        :style="isCategory(index, '{{ $cat['id'] }}') ? 'background-color: #111827; border-color: #111827;' : ''">
                                        {{ $cat['label'] }}
                                    </button>
                                @endif
                            @endforeach
                            <button type="button" @click="setImageCategory(index, 'trash')"
                                title="Trash"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border transition-colors"
                                :class="isCategory(index, 'trash') ? 'text-white' : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700'"
                                :style="isCategory(index, 'trash') ? 'background-color: #dc2626; border-color: #dc2626;' : ''">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100 dark:border-gray-700"
                        x-show="image.category !== 'trash'">

                        @if(in_array('cover', $allowedButtons))
                            <button type="button" @click="setImageType(image.url, 'cover')"
                                class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                :class="isType(image.url, 'cover') ? 'text-white' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'"
                                :style="isType(image.url, 'cover') ? 'background-color: #16a34a; border-color: #16a34a;' : ''">
                                Cover
                            </button>
                        @endif

                        @if(in_array('grid', $allowedButtons))
                            <button type="button" @click="setImageType(image.url, 'grid')"
                                class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                :class="isType(image.url, 'grid') ? 'text-white' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'"
                                :style="isType(image.url, 'grid') ? 'background-color: #2563eb; border-color: #2563eb;' : ''">
                                Grid
                            </button>
                        @endif

                        @if(in_array('hover', $allowedButtons))
                            <button type="button" @click="setImageType(image.url, 'hover')"
                                class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                :class="isType(image.url, 'hover') ? 'text-white' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'"
                                :style="isType(image.url, 'hover') ? 'background-color: #9333ea; border-color: #9333ea;' : ''">
                                Hover
                            </button>
                        @endif

                        @if(in_array('image_1', $allowedButtons))
                            <button type="button" @click="setImageType(image.url, 'image_1')"
                                class="flex-1 px-2 py-1 text-xs font-medium text-center rounded border transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                :class="isType(image.url, 'image_1') ? 'text-white' : 'bg-white border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600'"
                                :style="isType(image.url, 'image_1') ? 'background-color: #2563eb; border-color: #2563eb;' : ''">
                                Main Image
                            </button>
                        @endif

                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

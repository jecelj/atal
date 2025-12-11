<div x-data="{ open: false, img: '' }" x-on:open-lightbox.window="open = true; img = $event.detail.url" x-show="open"
    x-transition.opacity
    style="position: fixed; inset: 0; z-index: 100000; background-color: rgba(0,0,0,0.9); display: flex; align-items: center; justify-content: center; padding: 20px;"
    x-cloak @keydown.escape.window="open = false" @click="open = false">
    <!-- Close Button -->
    <button @click="open = false"
        style="position: absolute; top: 20px; right: 20px; color: white; background: transparent; border: none; font-size: 30px; cursor: pointer;">&times;</button>

    <!-- Image -->
    <img :src="img" @click.stop
        style="max-height: 90vh; max-width: 90vw; object-fit: contain; border-radius: 4px; box-shadow: 0 0 20px rgba(0,0,0,0.5);" />
</div>
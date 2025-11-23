import './bootstrap';

// Fix FilePond single file upload width issue
// Use multiple strategies to ensure it works with Livewire and dynamic content

console.log('[FilePond Fix] Script loaded');

// Strategy 1: Immediate fix on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function () {
    console.log('[FilePond Fix] DOMContentLoaded fired');
    fixFilepondWidths();

    // Strategy 2: MutationObserver for dynamic content
    const observer = new MutationObserver(function (mutations) {
        fixFilepondWidths();
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Strategy 3: Fix on window resize
    window.addEventListener('resize', fixFilepondWidths);
});

// Strategy 4: Also try to fix immediately (before DOMContentLoaded)
fixFilepondWidths();

function fixFilepondWidths() {
    const filepondLists = document.querySelectorAll('ul.filepond--list');
    console.log('[FilePond Fix] Found', filepondLists.length, 'FilePond lists');

    filepondLists.forEach(list => {
        const items = list.querySelectorAll('li.filepond--item');
        console.log('[FilePond Fix] List has', items.length, 'items');

        // Only fix if there's exactly one item (single file upload)
        if (items.length === 1) {
            const item = items[0];
            console.log('[FilePond Fix] Fixing single item, current width:', item.style.width);
            item.style.setProperty('width', '100%', 'important');
            item.style.setProperty('max-width', '100%', 'important');
            console.log('[FilePond Fix] After fix, width:', item.style.width);
        }
    });
}

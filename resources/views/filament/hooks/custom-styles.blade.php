<style>
    /* Sticky Header Logic */
    .fi-header {
        position: sticky;
        top: 0;
        z-index: 20;
        background-color: rgb(255, 255, 255);
        /* White background to cover content */
        border-bottom: 1px solid rgb(229, 231, 235);
        /* Subtle border */
        padding-top: 1rem;
        padding-bottom: 1rem;
        margin-top: -1rem;
        /* Offset padding */
    }

    /* Dark mode support */
    .dark .fi-header {
        background-color: rgb(17, 24, 39);
        /* Gray-900 */
        border-bottom-color: rgb(55, 65, 81);
        /* Gray-700 */
    }
</style>
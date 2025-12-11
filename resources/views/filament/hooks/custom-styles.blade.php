<style>
    /* Floating "Box" Header for Resource Pages */
    .fi-header {
        position: sticky;
        top: 1rem;
        z-index: 20;

        /* Box Styling (Card-like) */
        background-color: white;
        border-radius: 0.75rem;
        /* rounded-xl */
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        /* shadow-sm */
        border: 1px solid rgba(229, 231, 235, 1);
        /* gray-200 */

        /* Spacing compensation */
        /* Pull it up slightly from default position, but keep it floating */
        margin-top: -1.5rem !important;
        padding: 1rem 1.5rem;
        margin-bottom: 2rem;

        /* Ensure it sits nicely */
        width: 100%;
    }

    /* Dark Mode Styling */
    .dark .fi-header {
        background-color: rgb(24, 24, 27);
        /* gray-900 */
        border-color: rgba(255, 255, 255, 0.10);
        /* white/10 */
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.50);
    }

    /* Ensure actions align nicely */
    .fi-header-actions {
        align-items: center;
    }
</style>
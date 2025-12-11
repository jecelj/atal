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

        /* Visual Balance Correction: More top padding to account for line-height bias */
        padding-top: 2rem !important;
        padding-bottom: 1.5rem !important;
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;

        margin-bottom: 2rem;

        /* Ensure it sits nicely */
        width: 100%;

        /* Ensure Flex Alignment */
        display: flex;
        align-items: center;
        justify-content: space-between;
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

    /* CRITICAL FIX: Ensure Topbar (User Menu) is ABOVE the Sticky Header */
    /* AND User requested Topbar NOT to be sticky */
    .fi-topbar {
        position: relative !important;
        /* Was sticky by default, now scrolls away */
        z-index: 50 !important;
        /* Higher than header's 20 */
    }

    /* Fix Dropdown Menu Z-Index (User Menu, Tables, etc.) */
    /* Must be higher than sticky header (z-20) */
    /* Using extremely high value to guarantee visibility */
    .fi-dropdown-panel {
        z-index: 100 !important;
    }
</style>
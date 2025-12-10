<style>
    /* Sticky Header for Resource Pages */
    .fi-header {
        position: sticky;
        top: 0;
        z-index: 20;
        /* Blur effect for transparency */
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);

        /* Light Mode Background (Translucent White) */
        background-color: rgba(255, 255, 255, 0.90);

        /* Smooth transition */
        transition: box-shadow 0.3s ease-in-out;

        /* Border just in case */
        border-bottom: 1px solid rgba(229, 231, 235, 0.5);
        /* gray-200 */

        /* Spacing compensation */
        /* Typically Filament has 2rem (p-8) padding. We pull -2rem top and add padding back */
        /* This makes it stick to the VERY top of the window */
        margin-top: -2rem !important;
        padding-top: 1.5rem;
        padding-bottom: 1rem;
        margin-bottom: 2rem;

        /* Horizontal Glue (Optional, assuming p-8 container) */
        /* margin-left: -2rem; */
        /* margin-right: -2rem; */
        /* padding-left: 2rem; */
        /* padding-right: 2rem; */
    }

    /* Dark Mode Background (Translucent Gray-950) */
    .dark .fi-header {
        background-color: rgba(24, 24, 27, 0.90);
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    /* Ensure actions align nicely */
    .fi-header-actions {
        align-items: center;
    }
</style>
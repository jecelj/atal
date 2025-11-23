<style>
    /* No custom styles - using default Filament/FilePond styling */
    /* Force 5 columns for FilePond gallery on desktop */
    @media (min-width: 1024px) {

        /* Apply 5-column grid only to gallery fields, not single image/file fields */
        .filepond--item:not(.single-element .filepond--item) {
            width: calc(20% - 0.5em) !important;
        }

        /* Single image/file fields should use full width for better visibility */
        .single-element .filepond--item {
            width: calc(100% - 0.5em) !important;
        }
    }
</style>
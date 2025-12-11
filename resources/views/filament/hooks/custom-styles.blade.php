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

<style>
    /* Gallery Radio/Checkbox List Styling */
    /* Target the label container within the grid */
    .gallery-radio-list .grid>label,
    .gallery-checkbox-list .grid>label {
        display: flex !important;
        flex-direction: column-reverse !important;
        /* Image (in span) top, Input bottom */
        align-items: center !important;
        justify-content: center !important;
        background-color: #f3f4f6;
        /* Gray-100 equivalent for box effect */
        padding: 10px;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        /* Gray-200 */
        height: 100%;
        /* Fill grid cell */
        transition: all 0.2s;
        cursor: pointer;
    }

    /* Dark mode support */
    .dark .gallery-radio-list .grid>label,
    .dark .gallery-checkbox-list .grid>label {
        background-color: #1f2937;
        /* Gray-800 */
        border-color: #374151;
        /* Gray-700 */
    }

    /* Hover effect */
    .gallery-radio-list .grid>label:hover,
    .gallery-checkbox-list .grid>label:hover {
        border-color: rgb(var(--primary-500));
        background-color: rgba(var(--primary-500), 0.05);
    }

    /* Checked state styling (if possible to target via sibling selector? Filament uses standard inputs) */
    /* Input is inside the label, so we can't style parent based on child easily without :has(), but :has() is widely supported now */
    .gallery-radio-list .grid>label:has(input:checked),
    .gallery-checkbox-list .grid>label:has(input:checked) {
        border-color: rgb(var(--primary-500));
        background-color: rgba(var(--primary-500), 0.1);
        box-shadow: 0 0 0 2px rgb(var(--primary-500));
    }

    /* Hide the default span margin if any */
    .gallery-radio-list .grid>label>span,
    .gallery-checkbox-list .grid>label>span {
        width: 100%;
        display: flex;
        justify-content: center;
    }
</style>
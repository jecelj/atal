<?php
/**
 * Export Handler
 * Handles yacht export to JSON
 */

class Galeon_Export_Handler
{
    private $field_mapper;

    public function __construct()
    {
        $this->field_mapper = new Galeon_Field_Mapper();
    }

    /**
     * Export single yacht for testing
     * 
     * @param int $post_id WordPress post ID
     * @return array Result with success status and data/error
     */
    public function export_single_yacht($post_id)
    {
        try {
            $this->log("Starting export for post ID: {$post_id}");

            // Get post
            $post = get_post($post_id);
            if (!$post) {
                return [
                    'success' => false,
                    'error' => "Post not found: {$post_id}",
                ];
            }

            // Check if it's in correct category
            $categories = get_the_category($post_id);
            $valid_categories = ['flybridge', 'gto', 'hardtop', 'skydeck', 'explorer'];
            $is_valid = false;

            foreach ($categories as $category) {
                if (in_array($category->slug, $valid_categories)) {
                    $is_valid = true;
                    break;
                }
            }

            if (!$is_valid) {
                return [
                    'success' => false,
                    'error' => "Post is not in a valid yacht category",
                ];
            }

            // Get ACF fields
            if (!function_exists('get_fields')) {
                return [
                    'success' => false,
                    'error' => "ACF plugin not active",
                ];
            }

            $acf_fields = get_fields($post_id);
            if (!$acf_fields) {
                $acf_fields = [];
            }

            // Map fields
            $data = $this->field_mapper->map_yacht_fields($post, $acf_fields);

            $this->log("Export completed successfully");

            return [
                'success' => true,
                'data' => $data,
            ];

        } catch (Exception $e) {
            $this->log("Export failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Export all yachts (for later use)
     * 
     * @return array Result with success status and data/error
     */
    public function export_all_yachts()
    {
        // TODO: Implement after single yacht test is successful
        return [
            'success' => false,
            'error' => 'Not implemented yet - use single yacht export for testing',
        ];
    }

    /**
     * Log message
     */
    private function log($message)
    {
        error_log('[Galeon Export] ' . $message);
    }
}

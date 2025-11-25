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
     * Export all yachts from valid categories
     * 
     * @return array Result with success status and data/error
     */
    public function export_all_yachts()
    {
        global $wpdb;

        $this->log("Starting bulk export of all yachts");

        // Get all posts from valid categories
        $valid_categories = ['flybridge', 'gto', 'hardtop', 'skydeck', 'explorer'];

        $category_ids = [];
        foreach ($valid_categories as $slug) {
            $term = get_term_by('slug', $slug, 'category');
            if ($term) {
                $category_ids[] = $term->term_id;
            }
        }

        if (empty($category_ids)) {
            return [
                'success' => false,
                'error' => 'No valid categories found',
            ];
        }

        // Get all published posts in these categories
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'category__in' => $category_ids,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $posts = get_posts($args);

        if (empty($posts)) {
            return [
                'success' => false,
                'error' => 'No yachts found in valid categories',
            ];
        }

        $this->log("Found " . count($posts) . " yachts to export");

        $exported_yachts = [];
        $failed_yachts = [];

        foreach ($posts as $post) {
            $result = $this->export_single_yacht($post->ID);

            if ($result['success']) {
                $exported_yachts[] = $result['data'];
                $this->log("Exported: {$post->post_title} (ID: {$post->ID})");
            } else {
                $failed_yachts[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'error' => $result['error'],
                ];
                $this->log("Failed to export: {$post->post_title} (ID: {$post->ID}) - {$result['error']}");
            }
        }

        return [
            'success' => true,
            'total' => count($posts),
            'exported' => count($exported_yachts),
            'failed' => count($failed_yachts),
            'yachts' => $exported_yachts,
            'errors' => $failed_yachts,
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

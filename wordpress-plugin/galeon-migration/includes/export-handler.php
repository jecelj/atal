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

    /**
     * Export ACF field configuration for Used Yachts
     * 
     * @return array Result with success status and field configuration
     */
    public function export_used_yacht_fields()
    {
        try {
            $this->log("Starting ACF field configuration export for Used Yachts");

            if (!function_exists('acf_get_field_groups')) {
                return [
                    'success' => false,
                    'error' => 'ACF plugin not active',
                ];
            }

            // Get ALL field groups without filter
            $field_groups = acf_get_field_groups();

            $used_yacht_group = null;
            $available_groups = [];

            foreach ($field_groups as $group) {
                $available_groups[] = [
                    'title' => $group['title'],
                    'key' => $group['key'],
                    'location' => $group['location'] ?? 'unknown',
                ];

                // Match exact key or title (case-insensitive)
                if (
                    $group['key'] === 'group_68b0a977aef33' ||
                    strtolower(trim($group['title'])) === 'used yachts'
                ) {
                    $used_yacht_group = $group;
                    break;
                }
            }

            if (!$used_yacht_group) {
                // Return detailed error with list of found groups
                $debug_list = array_map(function ($g) {
                    return "Title: '{$g['title']}', Key: '{$g['key']}'";
                }, $available_groups);

                return [
                    'success' => false,
                    'error' => 'Group not found. Available groups: ' . implode(' | ', $debug_list),
                    'available_groups' => $available_groups,
                ];
            }

            // Get all fields in this group
            $fields = acf_get_fields($used_yacht_group['key']);

            if (empty($fields)) {
                return [
                    'success' => false,
                    'error' => 'No fields found in Used yachts group',
                ];
            }

            $field_config = [];
            $order = 0;

            foreach ($fields as $field) {
                // Debug: Log field type for gallery field
                if ($field['name'] === 'galerie') {
                    $this->log("Gallery field type: " . $field['type']);
                }

                $field_data = [
                    'field_key' => $field['name'],
                    'field_type' => $this->map_acf_type_to_master($field['type']),
                    'label' => $field['label'],
                    'group' => $used_yacht_group['title'],
                    'is_required' => !empty($field['required']),
                    'is_multilingual' => false, // Used yachts are not multilingual
                    'order' => $order++,
                    'options' => null,
                ];

                // Handle select fields
                if ($field['type'] === 'select' && !empty($field['choices'])) {
                    $options = [];
                    foreach ($field['choices'] as $value => $label) {
                        $options[] = [
                            'value' => $value,
                            'label' => $label,
                        ];
                    }
                    $field_data['options'] = $options;
                }

                $field_config[] = $field_data;
            }

            $this->log("Exported " . count($field_config) . " fields");

            return [
                'success' => true,
                'fields' => $field_config,
                'count' => count($field_config),
            ];

        } catch (Exception $e) {
            $this->log("Field export failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map ACF field type to Master field type
     */
    private function map_acf_type_to_master($acf_type)
    {
        $type_map = [
            'text' => 'text',
            'textarea' => 'textarea',
            'wysiwyg' => 'richtext',
            'number' => 'number',
            'date_picker' => 'date',
            'select' => 'select',
            'image' => 'image',
            'galerie' => 'gallery',  // ACF Galleries 4
            'acfg4_gallery' => 'gallery',
            'gallery' => 'gallery',
            'file' => 'file',
        ];

        return $type_map[$acf_type] ?? 'text';
    }

    /**
     * Export single Used Yacht
     * 
     * @param int $post_id WordPress post ID
     * @return array Result with success status and data/error
     */
    public function export_single_used_yacht($post_id)
    {
        try {
            $this->log("Starting Used Yacht export for post ID: {$post_id}");

            // Get post
            $post = get_post($post_id);
            if (!$post) {
                return [
                    'success' => false,
                    'error' => "Post not found: {$post_id}",
                ];
            }

            // Check if it's in preowned-yachts category
            $categories = get_the_category($post_id);
            $is_used_yacht = false;

            foreach ($categories as $category) {
                if ($category->slug === 'preowned-yachts') {
                    $is_used_yacht = true;
                    break;
                }
            }

            if (!$is_used_yacht) {
                return [
                    'success' => false,
                    'error' => "Post is not in 'preowned-yachts' category",
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

            // Map fields for Used Yacht
            $data = $this->map_used_yacht_fields($post, $acf_fields);

            $this->log("Used Yacht export completed successfully");

            return [
                'success' => true,
                'data' => $data,
            ];

        } catch (Exception $e) {
            $this->log("Used Yacht export failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map Used Yacht fields
     */
    private function map_used_yacht_fields($post, $acf_fields)
    {
        $data = [
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'state' => 'published',
            'custom_fields' => [],
            'media' => [],
        ];

        // Map all ACF fields to custom_fields
        foreach ($acf_fields as $key => $value) {
            // Skip image/gallery fields - they'll be in media
            if (is_array($value) && isset($value['url'])) {
                // Single image field (standard ACF image)
                $data['media'][$key] = [
                    [
                        'url' => $value['url'],
                        'name' => basename($value['url']),
                    ]
                ];
            } elseif (is_array($value) && !empty($value)) {
                // Check if it's a gallery
                // ACF Galleries 4 structure: [{'attachment': {...}, 'metadata': {'full': {'file_url': '...'}}}]
                // Standard ACF gallery: [{'url': '...'}]

                $is_gallery = true;
                $gallery_images = [];

                foreach ($value as $item) {
                    // Handle WP_Post objects (ACF gallery with 'Post Object' return format)
                    if (is_object($item) && $item instanceof WP_Post) {
                        $attachment_id = $item->ID;
                        $image_url = wp_get_attachment_url($attachment_id);

                        if ($image_url) {
                            $gallery_images[] = [
                                'url' => $image_url,
                                'name' => basename($image_url),
                                'attachment_id' => $attachment_id,
                            ];
                        }
                        continue;
                    }

                    // Handle arrays
                    if (!is_array($item)) {
                        $is_gallery = false;
                        break;
                    }

                    // Check for ACF Galleries 4 structure (array format)
                    if (isset($item['metadata']['full']['file_url'])) {
                        $attachment_id = isset($item['attachment']['ID']) ? $item['attachment']['ID'] : null;
                        $gallery_images[] = [
                            'url' => $item['metadata']['full']['file_url'],
                            'name' => basename($item['metadata']['full']['file_url']),
                            'attachment_id' => $attachment_id,
                        ];
                    }
                    // Check for standard ACF gallery structure
                    elseif (isset($item['url'])) {
                        $attachment_id = isset($item['ID']) ? $item['ID'] : (isset($item['id']) ? $item['id'] : null);
                        $gallery_images[] = [
                            'url' => $item['url'],
                            'name' => basename($item['url']),
                            'attachment_id' => $attachment_id,
                        ];
                    }
                    // Not a gallery item
                    else {
                        $is_gallery = false;
                        break;
                    }
                }

                if ($is_gallery && !empty($gallery_images)) {
                    // Sort gallery images by menu_order from WordPress attachments
                    $gallery_images = $this->sort_gallery_by_menu_order($gallery_images);

                    // Remove attachment_id from final output (only needed for sorting)
                    foreach ($gallery_images as &$image) {
                        unset($image['attachment_id']);
                    }

                    $data['media'][$key] = $gallery_images;
                } else {
                    $data['custom_fields'][$key] = $value;
                }
            } else {
                $data['custom_fields'][$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Export all Used Yachts from preowned-yachts category
     * 
     * @return array Result with success status and data/error
     */
    public function export_all_used_yachts()
    {
        $this->log("Starting bulk export of all Used Yachts");

        // Get preowned-yachts category
        $category = get_term_by('slug', 'preowned-yachts', 'category');

        if (!$category) {
            return [
                'success' => false,
                'error' => 'preowned-yachts category not found',
            ];
        }

        // Get all published posts in this category
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'category__in' => [$category->term_id],
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $posts = get_posts($args);

        if (empty($posts)) {
            return [
                'success' => false,
                'error' => 'No Used Yachts found in preowned-yachts category',
            ];
        }

        $this->log("Found " . count($posts) . " Used Yachts to export");

        $exported_yachts = [];
        $failed_yachts = [];

        foreach ($posts as $post) {
            $result = $this->export_single_used_yacht($post->ID);

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
     * Sort gallery images by WordPress attachment menu_order
     * 
     * @param array $gallery_images Array of images with attachment_id
     * @return array Sorted array of images
     */
    private function sort_gallery_by_menu_order($gallery_images)
    {
        try {
            global $wpdb;

            // Validate input
            if (!is_array($gallery_images) || empty($gallery_images)) {
                $this->log("Invalid or empty gallery_images array");
                return $gallery_images;
            }

            // Extract attachment IDs
            $attachment_ids = array_filter(array_column($gallery_images, 'attachment_id'));

            if (empty($attachment_ids)) {
                // No attachment IDs found, return original order
                $this->log("No attachment IDs found for sorting, keeping original order");
                return $gallery_images;
            }

            // Query WordPress database for menu_order
            $placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));

            // Use call_user_func_array for PHP 5.3+ compatibility
            $query = call_user_func_array(
                array($wpdb, 'prepare'),
                array_merge(
                    array("SELECT ID, menu_order FROM {$wpdb->posts} WHERE ID IN ($placeholders)"),
                    array_values($attachment_ids)
                )
            );

            $results = $wpdb->get_results($query, OBJECT_K);

            if (empty($results)) {
                $this->log("No menu_order results found from database");
                return $gallery_images;
            }

            // Add menu_order to each image
            foreach ($gallery_images as &$image) {
                if (!empty($image['attachment_id']) && isset($results[$image['attachment_id']])) {
                    $image['menu_order'] = (int) $results[$image['attachment_id']]->menu_order;
                } else {
                    $image['menu_order'] = 0; // Default to 0 if not found
                }
            }

            // Sort by menu_order using compatible comparison
            usort($gallery_images, function ($a, $b) {
                $a_order = isset($a['menu_order']) ? $a['menu_order'] : 0;
                $b_order = isset($b['menu_order']) ? $b['menu_order'] : 0;

                if ($a_order == $b_order) {
                    return 0;
                }
                return ($a_order < $b_order) ? -1 : 1;
            });

            // Remove menu_order from images (only needed for sorting)
            foreach ($gallery_images as &$image) {
                unset($image['menu_order']);
            }

            $this->log("Sorted " . count($gallery_images) . " gallery images by menu_order");

            return $gallery_images;

        } catch (Exception $e) {
            // Log error and return original order as fallback
            $this->log("Error sorting gallery: " . $e->getMessage());

            // Clean up any menu_order fields that might have been added
            foreach ($gallery_images as &$image) {
                if (isset($image['menu_order'])) {
                    unset($image['menu_order']);
                }
            }

            return $gallery_images;
        }
    }
}

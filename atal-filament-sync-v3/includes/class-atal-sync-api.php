<?php
class Atal_Sync_API
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'register_taxonomies']);
        // Hook to register fields and Falang settings
        add_action('acf/init', [$this, 'register_synced_fields']);
        add_action('init', [$this, 'register_falang_fields'], 30);
    }

    public function register_routes()
    {
        register_rest_route('atal-sync/v1', '/push', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_push'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function register_taxonomies()
    {
        register_taxonomy('yacht_brand', ['new_yachts', 'used_yachts'], [
            'labels' => [
                'name' => 'Brands',
                'singular_name' => 'Brand',
            ],
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'brand', 'hierarchical' => true],
        ]);

        register_taxonomy('yacht_model_type', ['new_yachts', 'used_yachts'], [
            'labels' => [
                'name' => 'Model Types',
                'singular_name' => 'Model Type',
            ],
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'model-type', 'hierarchical' => true],
        ]);
    }

    public function check_permission($request)
    {
        $api_key = $request->get_header('X-API-Key');
        $stored_key = get_option('atal_sync_api_key');
        return $api_key && $api_key === $stored_key;
    }

    public function handle_push($request)
    {
        $params = $request->get_json_params();
        $action = $params['action'] ?? 'update';
        $items = $params['items'] ?? [];

        if (empty($items)) {
            return new WP_Error('no_items', 'No items to process', ['status' => 400]);
        }

        // Config Handling
        if ($action === 'config') {
            update_option('atal_sync_acf_config', $items);

            // Trigger auto-registration immediately
            $this->register_synced_fields();
            $this->register_falang_fields();

            return rest_ensure_response([
                'success' => true,
                'message' => 'Field configuration updated',
                'count' => count($items)
            ]);
        }

        $results = [];

        foreach ($items as $item) {
            try {
                if ($action === 'delete') {
                    $results[] = $this->delete_item($item);
                } else {
                    $results[] = $this->update_item($item);
                }
            } catch (Exception $e) {
                $results[] = [
                    'id' => $item['id'] ?? 'unknown',
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'results' => $results
        ]);
    }

    /**
     * Registers ACF fields stored in options.
     */
    public function register_synced_fields()
    {
        if (!function_exists('acf_add_local_field_group'))
            return;

        $groups = get_option('atal_sync_acf_config', []);

        if (!empty($groups) && is_array($groups)) {
            foreach ($groups as $group) {
                acf_add_local_field_group($group);
            }
        }
    }

    /**
     * Registers Translatable fields in Falang options
     */
    public function register_falang_fields()
    {
        if (!class_exists('Falang\Core\Falang'))
            return;

        $groups = get_option('atal_sync_acf_config', []);
        if (empty($groups))
            return;

        $opt = get_option('falang', []);
        if (!isset($opt['post_type']))
            $opt['post_type'] = [];

        foreach ($groups as $group) {
            // Group key is like 'group_new_yacht'
            // We need to find the post type this group applies to
            $post_type = '';
            foreach ($group['location'] as $locGroup) {
                foreach ($locGroup as $rule) {
                    if ($rule['param'] === 'post_type' && $rule['operator'] === '==') {
                        $post_type = $rule['value'];
                        break 2;
                    }
                }
            }
            if (!$post_type)
                continue;

            if (!isset($opt['post_type'][$post_type]))
                $opt['post_type'][$post_type] = [];

            $opt['post_type'][$post_type]['translatable'] = 1;
            // Core fields
            $opt['post_type'][$post_type]['fields'] = ['post_title', 'post_name', 'post_content', 'post_excerpt'];

            // Custom Fields
            $meta_keys = [];
            foreach ($group['fields'] as $field) {
                // Only text/textarea/wysiwyg or explicitly marked
                if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                    $meta_keys[] = $field['name'];
                }
            }
            $opt['post_type'][$post_type]['meta_keys'] = $meta_keys;
        }

        update_option('falang', $opt);
    }

    private function delete_item($item)
    {
        $source_id = $this->get_source_id($item);

        $posts = get_posts([
            'post_type' => ['new_yachts', 'used_yachts', 'news', 'post'],
            'meta_key' => 'atal_source_id',
            'meta_value' => $source_id,
            'post_status' => 'any',
            'numberposts' => 1
        ]);

        if (!empty($posts)) {
            wp_delete_post($posts[0]->ID, true);
            return ['id' => $item['id'], 'success' => true, 'action' => 'deleted'];
        }

        return ['id' => $item['id'], 'success' => true, 'action' => 'not_found'];
    }

    private function update_item($data)
    {
        $type = $data['type'];
        $source_id = $data['source_id'];

        $post_type = match ($type) {
            'new_yacht' => 'new_yachts',
            'used_yacht' => 'used_yachts',
            'news' => 'post',
            default => 'post'
        };

        // Find existing
        // 1. Try modern key
        $existing = get_posts([
            'post_type' => $post_type,
            'meta_key' => 'atal_source_id',
            'meta_value' => $source_id,
            'post_status' => 'any',
            'numberposts' => 1
        ]);

        // 2. Try legacy key (_atal_source_id)
        if (empty($existing)) {
            $existing = get_posts([
                'post_type' => $post_type,
                'meta_key' => '_atal_source_id',
                'meta_value' => $source_id,
                'post_status' => 'any',
                'numberposts' => 1
            ]);
        }

        // 3. Special Fallback for News (Legacy)
        if (empty($existing) && $type === 'news') {
            // Try _atal_master_id
            // Note: source_id for news is "news-{id}", usually master sent raw ID before.
            // We need to extract ID from source_id.
            $rawId = str_replace('news-', '', $source_id);
            $existing = get_posts([
                'post_type' => 'post',
                'meta_key' => '_atal_master_id',
                'meta_value' => $rawId,
                'post_status' => 'any',
                'numberposts' => 1
            ]);

            // Try Slug as last resort
            if (empty($existing) && isset($data['slug'])) {
                $existing = get_posts([
                    'post_type' => 'post',
                    'meta_key' => '_atal_news_slug',
                    'meta_value' => $data['slug'],
                    'post_status' => 'any',
                    'numberposts' => 1
                ]);
            }
        }

        $post_id = !empty($existing) ? $existing[0]->ID : 0;

        // Post Data
        $post_data = [
            'ID' => $post_id,
            'post_type' => $post_type,
            'post_title' => $data['title'],
            'post_status' => 'publish',
            'post_name' => $data['slug'],
        ];

        if (isset($data['content']))
            $post_data['post_content'] = $data['content'];
        if (isset($data['published_at']))
            $post_data['post_date'] = $data['published_at'];

        if ($post_id) {
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }

        update_post_meta($post_id, 'atal_source_id', $source_id);

        // --- Handle Featured Image ---
        if (!empty($data['featured_image'])) {
            $att_id = $this->import_image($data['featured_image'], $post_id);
            if ($att_id) {
                set_post_thumbnail($post_id, $att_id);
            }
        }

        // --- Build Field Type Map from Config ---
        // This allows us to know which custom fields are images/galleries
        $fieldTypes = [];
        $groups = get_option('atal_sync_acf_config', []);
        if (!empty($groups)) {
            foreach ($groups as $group) {
                if (!empty($group['fields'])) {
                    foreach ($group['fields'] as $field) {
                        $fieldTypes[$field['name']] = $field['type'];
                    }
                }
            }
        }

        // --- Handle Custom Fields ---
        if (isset($data['custom_fields'])) {
            foreach ($data['custom_fields'] as $key => $value) {

                // 1. Special Case: Video Repeater Flattening (New Yachts)
                if ($key === 'video_url' && $post_type === 'new_yachts') {
                    $this->flatten_video_repeater($value, $post_id);
                    continue;
                }

                $fieldType = $fieldTypes[$key] ?? 'text';

                // 2. Handle Media Fields
                if ($fieldType === 'image' || $fieldType === 'file') {
                    if (!empty($value) && is_string($value)) {
                        $att_id = $this->import_image($value, $post_id);
                        if ($att_id)
                            update_post_meta($post_id, $key, $att_id);
                    }
                } elseif ($fieldType === 'gallery') {
                    if (!empty($value) && is_array($value)) {
                        $gallery_ids = $this->import_gallery($value, $post_id);
                        if ($gallery_ids)
                            update_post_meta($post_id, $key, $gallery_ids);
                    }
                } else {
                    // Normal fields
                    update_post_meta($post_id, $key, $value);
                }
            }
        }

        // --- Manual Gallery from Payload (if sent separately) ---
        if (isset($data['media']) && is_array($data['media']) && !empty($data['media'])) {
            // Check if there's a specific field for this, e.g. 'gallery_exterior'
            // If the user said "Gallery *", they likely have multiple galleries.
            // The 'media' key in payload is a comprehensive list. 
            // Usually we rely on custom_fields to map specific gallery fields.
            // So we skip 'media' if it's just a fallback, OR use it if specific fields failed.
            // For now, let's rely on custom_fields loop above handling 'gallery' types.
        }

        // Brand & Model Logic (Hierarchical)
        if (isset($data['brand'])) {
            $this->set_brand_taxonomy($post_id, $data['brand'], $data['model'] ?? null);
        }

        // Translations (Falang)
        if (isset($data['translations'])) {
            $this->save_translations($post_id, $data['translations']);
        }

        return ['id' => $data['id'], 'success' => true, 'wp_id' => $post_id];
    }

    private function import_image($url, $post_id)
    {
        if (empty($url))
            return false;

        // Check if already imported (by source url meta)
        $existing = get_posts([
            'post_type' => 'attachment',
            'meta_key' => '_atal_source_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        if (!empty($existing))
            return $existing[0]->ID;

        // Required WP files
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($url);
        if (is_wp_error($tmp))
            return false;

        $file_array = [
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        // Sideload
        // OPTIMIZATION: Disable image generation to speed up sync
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

        $id = media_handle_sideload($file_array, $post_id);

        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array');

        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }

        update_post_meta($id, '_atal_source_url', $url);
        return $id;
    }

    private function import_gallery($urls, $post_id)
    {
        $ids = [];
        if (!is_array($urls))
            return [];

        foreach ($urls as $url) {
            $id = $this->import_image($url, $post_id);
            if ($id)
                $ids[] = $id;
        }
        return $ids; // ACF Gallery expects array of IDs
    }

    private function flatten_video_repeater($value, $post_id)
    {
        // $value expects array of rows: [['url' => '...'], ['url' => '...']]
        // OR simply array of strings if flattened by Master (currently Master sends raw repeater array)
        if (is_string($value))
            $value = json_decode($value, true);
        if (!is_array($value))
            return;

        $count = 1;
        foreach ($value as $row) {
            $url = is_array($row) ? ($row['url'] ?? '') : $row;
            if ($count <= 3) {
                update_post_meta($post_id, "video_url_{$count}", $url);
            }
            $count++;
        }
        // Clear remaining
        for ($i = $count; $i <= 3; $i++) {
            update_post_meta($post_id, "video_url_{$i}", '');
        }
    }

    private function set_brand_taxonomy($post_id, $brandData, $modelData)
    {
        // 1. SYNC BRAND (yacht_brand)
        if (!empty($brandData)) {
            $brandTerm = term_exists($brandData['name'], 'yacht_brand');
            if (!$brandTerm) {
                $brandTerm = wp_insert_term($brandData['name'], 'yacht_brand', ['slug' => $brandData['slug']]);
            }
            if (!is_wp_error($brandTerm)) {
                $brandTermId = is_array($brandTerm) ? $brandTerm['term_id'] : $brandTerm;
                wp_set_object_terms($post_id, [(int) $brandTermId], 'yacht_brand');
                // Update ACF field 'brand'
                update_post_meta($post_id, 'brand', $brandTermId);
            }
        } else {
            wp_set_object_terms($post_id, [], 'yacht_brand');
            update_post_meta($post_id, 'brand', '');
        }

        // 2. SYNC MODEL TYPE (yacht_model_type)
        if (!empty($modelData)) {
            $modelTerm = term_exists($modelData['name'], 'yacht_model_type');
            if (!$modelTerm) {
                $modelTerm = wp_insert_term($modelData['name'], 'yacht_model_type', ['slug' => $modelData['slug']]);
            }
            if (!is_wp_error($modelTerm)) {
                $modelTermId = is_array($modelTerm) ? $modelTerm['term_id'] : $modelTerm;
                wp_set_object_terms($post_id, [(int) $modelTermId], 'yacht_model_type');
            }
        } else {
            wp_set_object_terms($post_id, [], 'yacht_model_type');
        }
    }

    private function save_translations($post_id, $translations)
    {
        if (!class_exists('Falang\Model\Falang_Model'))
            return;

        $model = new Falang\Model\Falang_Model();

        foreach ($translations as $langCode => $fields) {
            $language = $model->get_language_by_slug($langCode);
            if (!$language || empty($language->locale))
                continue;

            $prefix = '_' . $language->locale . '_';

            foreach ($fields as $key => $value) {
                // Map logical keys to WP/Meta keys
                $metaKey = match ($key) {
                    'title' => 'post_title',
                    'content' => 'post_content',
                    'name' => 'post_title', // For yachts
                    default => $key // Custom fields
                };

                // Save Prefixed Meta
                update_post_meta($post_id, $prefix . $metaKey, $value);
            }
            // Mark Published
            update_post_meta($post_id, $prefix . 'published', 1);
        }
    }

    private function get_source_id($item)
    {
        if (isset($item['source_id']))
            return $item['source_id'];
        return ($item['type'] === 'news' ? 'news-' : 'yacht-') . $item['id'];
    }
}

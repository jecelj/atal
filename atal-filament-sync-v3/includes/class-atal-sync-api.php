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
                'name' => 'Brands & Models',
                'singular_name' => 'Brand/Model',
            ],
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'brand', 'hierarchical' => true],
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
        $existing = get_posts([
            'post_type' => $post_type,
            'meta_key' => 'atal_source_id',
            'meta_value' => $source_id,
            'post_status' => 'any',
            'numberposts' => 1
        ]);

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

        // Custom Fields
        if (isset($data['custom_fields'])) {
            foreach ($data['custom_fields'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
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

    private function set_brand_taxonomy($post_id, $brandData, $modelData)
    {
        if (empty($brandData))
            return;

        // Get/Create Brand Term
        $brandTerm = term_exists($brandData['name'], 'yacht_brand');
        if (!$brandTerm) {
            $brandTerm = wp_insert_term($brandData['name'], 'yacht_brand', ['slug' => $brandData['slug']]);
        }
        $brandTermId = is_array($brandTerm) ? $brandTerm['term_id'] : $brandTerm;

        $termsToSet = [(int) $brandTermId];
        $mainTermId = $brandTermId;

        // Get/Create Model Term (Child of Brand)
        if (!empty($modelData)) {
            $modelTerm = term_exists($modelData['name'], 'yacht_brand', $brandTermId);
            if (!$modelTerm) {
                $modelTerm = wp_insert_term($modelData['name'], 'yacht_brand', [
                    'slug' => $modelData['slug'],
                    'parent' => $brandTermId
                ]);
            }
            $modelTermId = is_array($modelTerm) ? $modelTerm['term_id'] : $modelTerm;
            $termsToSet[] = (int) $modelTermId;
            $mainTermId = $modelTermId; // We usually save the most specific term to the "brand" field if it's acting as Model selector
        }

        wp_set_object_terms($post_id, $termsToSet, 'yacht_brand');

        // Update ACF field 'brand' with the main term ID (compatible with Taxonomy field type saving Term ID)
        update_post_meta($post_id, 'brand', $mainTermId);
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

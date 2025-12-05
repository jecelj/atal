<?php
/**
 * Debug endpoints za preverjanje uvoza
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    // Debug endpoint za preverjanje, kaj pride iz strani 1
    register_rest_route('atal-import/v1', '/debug-source', [
        'methods'  => 'GET',
        'callback' => 'atal_debug_source_data',
        'permission_callback' => '__return_true',
    ]);
    
    // Debug endpoint za preverjanje ACF field groups
    register_rest_route('atal-import/v1', '/debug-acf-groups', [
        'methods'  => 'GET',
        'callback' => 'atal_debug_acf_groups',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Debug: Kaj pride iz strani 1
 */
function atal_debug_source_data($request) {
    $url = get_option('atal_import_url');
    if (empty($url)) {
        return ['error' => 'Import URL not set'];
    }
    
    $lang = $request->get_param('lang') ?: 'en';
    $api = add_query_arg('lang', $lang, $url);
    
    if (defined('ATAL_IMPORT_API_KEY')) {
        $api = add_query_arg('key', ATAL_IMPORT_API_KEY, $api);
    }
    
    $response = wp_remote_get($api, ['timeout' => 30]);
    
    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (!is_array($data) || empty($data)) {
        return ['error' => 'No data received', 'raw' => substr($body, 0, 1000)];
    }
    
    $first_item = $data[0];
    $gallery_info = [];
    
    // Preveri gallery polje
    if (isset($first_item['acf']['gallery'])) {
        $gallery = $first_item['acf']['gallery'];
        $gallery_info = [
            'exists' => true,
            'type' => gettype($gallery),
            'count' => is_array($gallery) ? count($gallery) : 0,
            'first_item' => is_array($gallery) && !empty($gallery) ? $gallery[0] : null,
            'has_urls' => false,
        ];
        
        if (is_array($gallery) && !empty($gallery)) {
            $first = $gallery[0];
            if (is_array($first) && isset($first['url'])) {
                $gallery_info['has_urls'] = true;
                $gallery_info['sample_url'] = $first['url'];
            }
        }
    } else {
        $gallery_info = ['exists' => false];
    }
    
    return [
        'url' => $api,
        'items_count' => count($data),
        'first_item' => [
            'id' => $first_item['id'] ?? null,
            'title' => $first_item['title']['rendered'] ?? null,
            'acf_keys' => isset($first_item['acf']) ? array_keys($first_item['acf']) : [],
        ],
        'gallery' => $gallery_info,
        'full_first_item' => $first_item,
    ];
}

/**
 * Debug: ACF field groups na strani 2
 */
function atal_debug_acf_groups($request) {
    if (!function_exists('acf_get_field_groups')) {
        return ['error' => 'ACF not installed'];
    }
    
    $post_types = ['new_yachts', 'used_yachts'];
    $groups_info = [];
    
    foreach ($post_types as $post_type) {
        $groups = acf_get_field_groups(['post_type' => $post_type]);
        
        foreach ($groups as $group) {
            $fields = [];
            if (function_exists('acf_get_fields')) {
                $group_fields = acf_get_fields($group['ID']);
                if (is_array($group_fields)) {
                    foreach ($group_fields as $field) {
                        $fields[] = [
                            'name' => $field['name'],
                            'label' => $field['label'],
                            'type' => $field['type'],
                            'order' => isset($field['menu_order']) ? $field['menu_order'] : 0,
                        ];
                    }
                }
            }
            
            $groups_info[] = [
                'title' => $group['title'],
                'key' => $group['key'],
                'post_type' => $post_type,
                'fields_count' => count($fields),
                'fields' => $fields,
            ];
        }
    }
    
    return [
        'groups_count' => count($groups_info),
        'groups' => $groups_info,
    ];
}


<?php
/**
 * SCF (Secure Custom Fields / ACF) Dynamic Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'atal_sync_register_scf_fields', 20);

function atal_sync_register_scf_fields()
{
    // Check if ACF/SCF function exists
    if (!function_exists('acf_add_local_field_group')) {
        error_log('ATAL SYNC: acf_add_local_field_group function does NOT exist - SCF fields will not be registered');
        return;
    }

    // Get cached field definitions
    $field_groups = get_option('atal_sync_field_definitions');

    if (empty($field_groups) || !is_array($field_groups)) {
        error_log('ATAL SYNC: No field definitions found - run "Sync Field Definitions" first');
        return;
    }

    error_log('ATAL SYNC: Registering ' . count($field_groups) . ' field groups');

    // Register field groups
    foreach ($field_groups as $post_type => $group_data) {
        $fields = [];

        // Add static base fields
        // $fields[] = [
        //     'key' => 'field_' . $post_type . '_price',
        //     'label' => __('Price', 'atal-sync'),
        //     'name' => 'price',
        //     'type' => 'number',
        // ];

        // Add dynamic fields from Filament
        foreach ($group_data['fields'] as $field) {
            // Force taxonomy type for known taxonomy fields
            if ($field['name'] === 'brand') {
                $field['type'] = 'taxonomy';
                $field['taxonomy'] = 'yacht_brand';
            }
            // Model field is no longer separate - it's part of brand hierarchy
            // Skip model field registration
            if ($field['name'] === 'model') {
                continue; // Skip model field
            }

            // SPECIAL HANDLING: New Yachts 'video_url' -> Flatten to 3 text fields
            // Check based on NAME, regardless of type (it might be text/repeater)
            if (($post_type === 'new_yachts' || $post_type === 'new-yachts') && $field['name'] === 'video_url') {
                atal_log("FLATTENING video_url for {$post_type} (Type was: {$field['type']})");
                for ($i = 1; $i <= 3; $i++) {
                    $fields[] = [
                        'key' => 'field_' . $post_type . '_video_url_' . $i, // Explicit key
                        'label' => 'Video URL ' . $i,
                        'name' => 'video_url_' . $i,
                        'type' => 'text',
                        'instructions' => 'Enter YouTube/Vimeo URL',
                        'required' => 0,
                    ];
                }
                continue; // Skip adding the original video_url field
            }

            $acf_field = [
                'key' => $field['key'] ?? 'field_' . $post_type . '_' . $field['name'],
                'label' => $field['label'],
                'name' => $field['name'],
                'type' => atal_sync_map_field_type($field['type']),
                'required' => $field['required'] ? 1 : 0,
            ];

            // Add field-specific options
            if ($field['type'] === 'gallery') {
                $acf_field['type'] = 'gallery'; // ACF has native gallery
                $acf_field['return_format'] = 'id';
                $acf_field['library'] = 'all'; // Show ALL media, not just "uploaded to this post"
            } elseif ($field['type'] === 'image') {
                $acf_field['return_format'] = 'id';
                $acf_field['library'] = 'all'; // Show ALL media
            } elseif (($field['type'] === 'select' || $field['type'] === 'checkbox') && !empty($field['options'])) {
                $choices = [];
                foreach ($field['options'] as $option) {
                    $choices[$option['value']] = $option['label'];
                }
                $acf_field['choices'] = $choices;
            } elseif ($field['type'] === 'taxonomy') {
                $acf_field['taxonomy'] = $field['taxonomy']; // e.g., 'yacht_brand'
                $acf_field['field_type'] = 'select'; // Hierarchical select
                $acf_field['return_format'] = 'id';
                $acf_field['add_term'] = 0; // Disable adding new terms from here
                $acf_field['save_terms'] = 1; // Important: Save terms to post
                $acf_field['load_terms'] = 1; // Load terms from post
            } elseif ($field['type'] === 'repeater') {
                atal_log("Processing repeater field: {$field['name']} for Post Type: {$post_type}");

                // Handle other repeater fields
                $acf_field['type'] = 'repeater';
                $acf_field['layout'] = 'table';
                $acf_field['button_label'] = 'Add Row';

                // Define sub-fields for repeater
                // For video_url, we have a single 'url' sub-field
                $acf_field['sub_fields'] = [
                    [
                        'key' => $field['key'] . '_url',
                        'label' => 'URL',
                        'name' => 'url',
                        'type' => 'url',
                        'required' => 1,
                    ],
                ];
            }

            // Log field registration for debugging
            if ($field['name'] === 'brand') {
                atal_log("Registering field: " . $field['name'] . " | Type: " . $acf_field['type'] . " | Taxonomy: " . ($acf_field['taxonomy'] ?? 'N/A'));
            }

            $fields[] = $acf_field;
        }

        // Register ACF field group
        acf_add_local_field_group([
            'key' => 'group_' . $post_type,
            'title' => $group_data['title'],
            'fields' => $fields,
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => $post_type,
                    ],
                ],
            ],
        ]);
    }
}

function atal_sync_map_field_type($filament_type)
{
    $type_map = [
        'text' => 'text',
        'textarea' => 'textarea',
        'richtext' => 'wysiwyg',
        'number' => 'number',
        'date' => 'date_picker',
        'select' => 'select',
        'checkbox' => 'checkbox',
        'image' => 'image',
        'gallery' => 'gallery',
        'file' => 'file',
        'wysiwyg' => 'wysiwyg',
        'taxonomy' => 'taxonomy',
        'repeater' => 'repeater',
    ];

    return $type_map[$filament_type] ?? 'text';
}

/**
 * Function to fetch and cache fields (called manually)
 */
function atal_import_fields()
{
    $api_url = get_option('atal_sync_api_url');
    $api_key = get_option('atal_sync_api_key');

    if (empty($api_url) || empty($api_key)) {
        return ['error' => 'API URL or API Key not configured'];
    }

    $response = wp_remote_get($api_url . '/fields', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['field_groups'])) {
        return ['error' => 'Invalid API response'];
    }

    // Update cache
    update_option('atal_sync_field_definitions', $data['field_groups']);

    return ['imported' => count($data['field_groups']), 'message' => 'Successfully synced field definitions'];
}

// Register Falang translatable fields after ACF fields are registered
add_action('init', 'atal_sync_register_falang_fields', 30);

function atal_sync_register_falang_fields()
{
    // Only register if Falang is active
    if (!atal_is_falang_active()) {
        return;
    }

    atal_register_falang_fields();
}

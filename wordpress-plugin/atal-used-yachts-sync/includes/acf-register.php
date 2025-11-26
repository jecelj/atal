<?php
/**
 * Register ACF Field Groups for Used Yachts
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create ACF field groups from Master configuration
 */
function atal_used_yachts_register_acf_fields()
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    // Get field configuration from Master (stored in option)
    $field_config = get_option('atal_used_yachts_field_config', []);

    if (empty($field_config)) {
        return;
    }

    // Group fields by their group name
    $grouped_fields = [];
    foreach ($field_config as $field) {
        $group_name = $field['group'] ?? 'Additional Information';
        if (!isset($grouped_fields[$group_name])) {
            $grouped_fields[$group_name] = [];
        }
        $grouped_fields[$group_name][] = $field;
    }

    // Create ACF field group for each section
    foreach ($grouped_fields as $group_name => $fields) {
        $group_key = 'group_used_yacht_' . sanitize_title($group_name);

        $acf_fields = [];
        foreach ($fields as $field) {
            $acf_field = atal_used_yachts_convert_to_acf_field($field);
            if ($acf_field) {
                $acf_fields[] = $acf_field;
            }
        }

        if (empty($acf_fields)) {
            continue;
        }

        acf_add_local_field_group([
            'key' => $group_key,
            'title' => $group_name,
            'fields' => $acf_fields,
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'used_yacht',
                    ],
                ],
            ],
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
        ]);
    }
}
add_action('acf/init', 'atal_used_yachts_register_acf_fields');

/**
 * Convert Master field configuration to ACF field format
 */
function atal_used_yachts_convert_to_acf_field($field)
{
    $field_key = 'field_' . $field['field_key'];
    $field_name = $field['field_key'];

    $base_field = [
        'key' => $field_key,
        'label' => $field['label'],
        'name' => $field_name,
        'required' => $field['is_required'] ?? false,
    ];

    // Map Master field types to ACF field types
    switch ($field['field_type']) {
        case 'text':
            return array_merge($base_field, [
                'type' => 'text',
            ]);

        case 'textarea':
            return array_merge($base_field, [
                'type' => 'textarea',
                'rows' => 4,
            ]);

        case 'richtext':
            return array_merge($base_field, [
                'type' => 'wysiwyg',
                'tabs' => 'all',
                'toolbar' => 'full',
                'media_upload' => 1,
            ]);

        case 'number':
            return array_merge($base_field, [
                'type' => 'number',
            ]);

        case 'date':
            return array_merge($base_field, [
                'type' => 'date_picker',
                'display_format' => 'd/m/Y',
                'return_format' => 'Y-m-d',
            ]);

        case 'select':
            $choices = [];
            if (!empty($field['options'])) {
                foreach ($field['options'] as $option) {
                    $choices[$option['value']] = $option['label'];
                }
            }
            return array_merge($base_field, [
                'type' => 'select',
                'choices' => $choices,
            ]);

        case 'image':
            return array_merge($base_field, [
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ]);

        case 'gallery':
            return array_merge($base_field, [
                'type' => 'gallery',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ]);

        case 'file':
            return array_merge($base_field, [
                'type' => 'file',
                'return_format' => 'array',
                'library' => 'all',
            ]);

        case 'repeater':
            return array_merge($base_field, [
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => 'Add Row',
                'sub_fields' => [
                    [
                        'key' => $field_key . '_url',
                        'label' => 'URL',
                        'name' => 'url',
                        'type' => 'url',
                    ],
                ],
            ]);

        default:
            return null;
    }
}

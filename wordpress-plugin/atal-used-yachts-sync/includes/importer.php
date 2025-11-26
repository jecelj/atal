<?php
/**
 * Importer for Used Yachts
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import a single yacht
 */
function atal_used_yachts_import_yacht($yacht_data)
{
    try {
        // Check if yacht exists by slug
        $existing = get_page_by_path($yacht_data['slug'], OBJECT, 'used_yacht');

        $post_data = [
            'post_type' => 'used_yacht',
            'post_status' => $yacht_data['state'] === 'published' ? 'publish' : 'draft',
            'post_name' => $yacht_data['slug'],
        ];

        // Handle multilingual title
        if (is_array($yacht_data['name'])) {
            $default_lang = get_option('atal_used_yachts_default_language', 'en');
            $post_data['post_title'] = $yacht_data['name'][$default_lang] ?? reset($yacht_data['name']);
        } else {
            $post_data['post_title'] = $yacht_data['name'];
        }

        if ($existing) {
            $post_data['ID'] = $existing->ID;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id)) {
            return ['success' => false, 'error' => $post_id->get_error_message()];
        }

        // Set brand taxonomy
        if (!empty($yacht_data['brand'])) {
            $brand_term = get_term_by('name', $yacht_data['brand'], 'yacht_brand');
            if (!$brand_term) {
                $brand_term = wp_insert_term($yacht_data['brand'], 'yacht_brand');
                $brand_term = get_term($brand_term['term_id'], 'yacht_brand');
            }
            wp_set_object_terms($post_id, [$brand_term->term_id], 'yacht_brand');
        }

        // Set model taxonomy
        if (!empty($yacht_data['model'])) {
            $model_term = get_term_by('name', $yacht_data['model'], 'yacht_model');
            if (!$model_term) {
                $model_term = wp_insert_term($yacht_data['model'], 'yacht_model');
                $model_term = get_term($model_term['term_id'], 'yacht_model');
            }
            wp_set_object_terms($post_id, [$model_term->term_id], 'yacht_model');
        }

        // Import custom fields
        if (!empty($yacht_data['custom_fields'])) {
            foreach ($yacht_data['custom_fields'] as $field_key => $field_value) {
                update_field($field_key, $field_value, $post_id);
            }
        }

        // Import media
        if (!empty($yacht_data['media'])) {
            atal_used_yachts_import_media($post_id, $yacht_data['media']);
        }

        // Handle multilingual content (WPML/Polylang)
        if (is_array($yacht_data['name']) && function_exists('pll_save_post_translations')) {
            atal_used_yachts_handle_translations($post_id, $yacht_data);
        }

        return ['success' => true, 'post_id' => $post_id];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Import media files
 */
function atal_used_yachts_import_media($post_id, $media_data)
{
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Set timeout for large images
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    foreach ($media_data as $collection => $items) {
        if (empty($items)) {
            continue;
        }

        // Handle single image collections
        if (!is_array($items) || isset($items['url'])) {
            $items = [$items];
        }

        $attachment_ids = [];

        foreach ($items as $item) {
            if (empty($item['url'])) {
                continue;
            }

            // Download and attach image
            $attachment_id = atal_used_yachts_download_image($item['url'], $post_id);

            if ($attachment_id && !is_wp_error($attachment_id)) {
                $attachment_ids[] = $attachment_id;

                // Set as featured image if it's cover_image
                if ($collection === 'cover_image') {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }
        }

        // Store in ACF field or ACF Galleries 4
        if (!empty($attachment_ids)) {
            if ($collection === 'cover_image' || $collection === 'grid_image') {
                // Single image field
                update_field($collection, $attachment_ids[0], $post_id);
            } else {
                // Check if this is an ACF Galleries 4 field
                $field_object = get_field_object($collection, $post_id);

                if ($field_object && $field_object['type'] === 'acfg4_gallery') {
                    // ACF Galleries 4 format
                    update_post_meta($post_id, '_acfg4_' . $collection, $attachment_ids);
                } else {
                    // Standard ACF gallery
                    update_field($collection, $attachment_ids, $post_id);
                }
            }
        }
    }
}

/**
 * Download image from URL
 */
function atal_used_yachts_download_image($url, $post_id)
{
    $tmp = download_url($url);

    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_array = [
        'name' => basename($url),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        @unlink($file_array['tmp_name']);
    }

    return $attachment_id;
}

/**
 * Handle multilingual translations
 */
function atal_used_yachts_handle_translations($post_id, $yacht_data)
{
    // This is a placeholder for WPML/Polylang integration
    // Implementation depends on which multilingual plugin is used
}

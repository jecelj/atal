#!/bin/bash
# Script to replace atal_import_single_yacht function with Falang version

# Extract lines before the function (1-117)
sed -n '1,117p' /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer.php > /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer_new.php

# Add the new Falang function
cat >> /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer_new.php << 'EOFFUNCTION'
function atal_import_single_yacht($yacht_data)
{
    $post_type = $yacht_data['type'] === 'new' ? 'new_yachts' : 'used_yachts';
    $source_id = $yacht_data['source_id'];
    $state = $yacht_data['state'] ?? 'draft';

    atal_log("=== Importing Yacht (Falang) ===");
    atal_log("Source ID: $source_id");
    atal_log("Type: $post_type");
    atal_log("State: $state");

    // Determine WordPress post status
    $post_status = ($state === 'published') ? 'publish' : 'draft';

    // Get available translations from API
    if (!isset($yacht_data['translations'])) {
        atal_log("ERROR: No translations found for yacht");
        return false;
    }

    // Filter languages based on active site languages
    $active_languages = atal_get_active_languages();
    $available_langs = array_intersect(array_keys($yacht_data['translations']), $active_languages);

    if (empty($available_langs)) {
        atal_log("ERROR: No matching languages. Active: " . implode(', ', $active_languages));
        return false;
    }

    atal_log("Available languages: " . implode(', ', $available_langs));

    // --- Brand and Model Filtering Logic ---
    $allowed_brands = get_option('atal_sync_allowed_brands', []);
    $allowed_models = get_option('atal_sync_allowed_models', []);

    if (!empty($allowed_brands) || !empty($allowed_models)) {
        $yacht_brand_id = $yacht_data['brand']['id'] ?? null;
        $yacht_model_id = $yacht_data['model']['id'] ?? null;
        $allow_import = false;

        if ($yacht_model_id && in_array($yacht_model_id, $allowed_models)) {
            $allow_import = true;
            atal_log("Yacht allowed by Model ID: $yacht_model_id");
        } elseif ($yacht_brand_id && in_array($yacht_brand_id, $allowed_brands)) {
            $cached_data = get_option('atal_sync_available_data', []);
            $cached_models = $cached_data['models'] ?? [];
            $models_of_this_brand_are_restricted = false;

            foreach ($cached_models as $m) {
                if (isset($m['brand_id']) && $m['brand_id'] == $yacht_brand_id) {
                    if (in_array($m['id'], $allowed_models)) {
                        $models_of_this_brand_are_restricted = true;
                        break;
                    }
                }
            }

            if (!$models_of_this_brand_are_restricted) {
                $allow_import = true;
                atal_log("Yacht allowed by Brand ID: $yacht_brand_id");
            }
        }

        if (!$allow_import) {
            atal_log("Skipping yacht - Brand/Model not allowed");
            return false;
        }
    }

    // Get field definitions to know which fields are images/files
    $field_groups = get_option('atal_sync_field_definitions');
    $field_types = [];

    if (!empty($field_groups)) {
        foreach ($field_groups as $group) {
            foreach ($group['fields'] as $field) {
                $field_types[$field['name']] = $field['type'];
            }
        }
    }

    // Get default language
    $default_lang = atal_get_default_language_falang();
    atal_log("Default language: $default_lang");

    if (!isset($yacht_data['translations'][$default_lang])) {
        atal_log("ERROR: No translation for default language: $default_lang");
        return false;
    }

    $default_translation = $yacht_data['translations'][$default_lang];

    // --- FIND OR CREATE POST ---
    // Check if post already exists by source_id
    $existing_posts = get_posts([
        'post_type' => $post_type,
        'meta_key' => '_atal_source_id',
        'meta_value' => $source_id,
        'posts_per_page' => 1,
        'post_status' => 'any',
    ]);

    $post_id = 0;

    if (!empty($existing_posts)) {
        // Update existing post
        $post_id = $existing_posts[0]->ID;
        atal_log("Updating existing post: $post_id");

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $default_translation['title'],
            'post_content' => $default_translation['description'] ?? '',
            'post_status' => $post_status,
        ]);
    } else {
        // Create new post
        atal_log("Creating new post");

        $post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_title' => $default_translation['title'],
            'post_content' => $default_translation['description'] ?? '',
            'post_status' => $post_status,
        ]);

        if (is_wp_error($post_id)) {
            atal_log("ERROR creating post: " . $post_id->get_error_message());
            return false;
        }

        // Set source ID meta
        update_post_meta($post_id, '_atal_source_id', $source_id);
        atal_log("Created post ID: $post_id");
    }

    // --- SAVE DEFAULT LANGUAGE CUSTOM FIELDS ---
    if (function_exists('update_field') && isset($default_translation['custom_fields'])) {
        foreach ($default_translation['custom_fields'] as $key => $value) {
            if ($key === '_debug_configured_fields') {
                continue;
            }

            if (empty($value)) {
                update_field($key, $value, $post_id);
                continue;
            }

            $type = $field_types[$key] ?? 'text';

            // Handle Media Fields
            if ($type === 'image' || $type === 'file') {
                if (is_string($value) && !empty($value) && parse_url($value, PHP_URL_SCHEME)) {
                    $attachment_id = atal_import_image($value, $post_id);
                    if ($attachment_id) {
                        update_field($key, $attachment_id, $post_id);
                    }
                }
            } elseif ($type === 'gallery') {
                if (is_array($value)) {
                    $gallery_ids = atal_import_gallery($value, $post_id);
                    update_field($key, $gallery_ids, $post_id);
                }
            } else {
                // Normal field
                update_field($key, $value, $post_id);
            }
        }
    }

    // --- IMPORT MEDIA (ONCE) ---
    if (!empty($yacht_data['media']['featured_image'])) {
        $attachment_id = atal_import_image($yacht_data['media']['featured_image'], $post_id);
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    if (!empty($yacht_data['media']['gallery_exterior'])) {
        $gallery_ids = atal_import_gallery($yacht_data['media']['gallery_exterior'], $post_id);
        if (function_exists('update_field')) {
            update_field('gallery_exterior', $gallery_ids, $post_id);
        }
    }

    if (!empty($yacht_data['media']['gallery_interior'])) {
        $gallery_ids = atal_import_gallery($yacht_data['media']['gallery_interior'], $post_id);
        if (function_exists('update_field')) {
            update_field('gallery_interior', $gallery_ids, $post_id);
        }
    }

    // --- SET TAXONOMIES (ONCE) ---
    if (isset($yacht_data['brand'])) {
        $brand_name = $yacht_data['brand']['name'];
        
        // Create/get brand term (no language parameter for Falang)
        $brand_term = atal_get_or_create_term_falang($brand_name, 'yacht_brand', 0);

        if ($brand_term) {
            if (isset($yacht_data['model']) && !empty($yacht_data['model'])) {
                $model_name = $yacht_data['model']['name'];
                $model_term = atal_get_or_create_term_falang($model_name, 'yacht_brand', $brand_term);

                if ($model_term) {
                    wp_set_object_terms($post_id, [(int) $model_term], 'yacht_brand');
                    
                    // Also save to ACF field
                    if (function_exists('update_field')) {
                        update_field('brand', [(int) $model_term], $post_id);
                    }
                }
            } else {
                wp_set_object_terms($post_id, [(int) $brand_term], 'yacht_brand');
                
                // Also save to ACF field
                if (function_exists('update_field')) {
                    update_field('brand', [(int) $brand_term], $post_id);
                }
            }
        }
    }

    // --- SAVE TRANSLATIONS FOR OTHER LANGUAGES ---
    $multilingual_fields = atal_get_multilingual_fields();
    atal_save_all_translations($post_id, $yacht_data['translations'], $multilingual_fields);

    atal_log("Yacht import complete. Post ID: $post_id");
    return true;
}

EOFFUNCTION

# Extract lines after the function (496 to end)
sed -n '496,$p' /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer.php >> /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer_new.php

# Replace the original file
mv /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer_new.php /Users/mitjajecelj/Downloads/atal-yachts/atal-admin/wordpress-plugin/atal-filament-sync/includes/importer.php

echo "✅ Function replaced successfully"

<?php
/**
 * Importer Logic
 */

if (!defined('ABSPATH')) {
    exit;
}

function atal_log($message)
{
    $log_file = WP_CONTENT_DIR . '/atal-sync-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND);
}

function atal_import_yachts()
{
    atal_log("Starting Yacht Import...");

    $api_url = get_option('atal_sync_api_url');
    $api_key = get_option('atal_sync_api_key');

    if (empty($api_url) || empty($api_key)) {
        atal_log("Error: API URL or Key missing");
        return ['error' => 'API URL or API Key not configured'];
    }

    // Fetch yachts from Filament
    $response = wp_remote_get($api_url . '/yachts', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        atal_log("API Error: " . $response->get_error_message());
        return ['error' => $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['yachts'])) {
        atal_log("Error: Invalid API response format");
        return ['error' => 'Invalid API response'];
    }

    atal_log("Found " . count($data['yachts']) . " yachts in API response");

    $imported = 0;
    $errors = [];

    // Get Polylang languages - this is no longer needed here as it's handled per yacht in single_yacht function
    // $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['en'];

    foreach ($data['yachts'] as $yacht_data) {
        try {
            atal_log("Processing yacht: " . ($yacht_data['id'] ?? 'unknown'));
            $result = atal_import_single_yacht($yacht_data);
            if ($result) {
                $imported++;
            }
        } catch (Exception $e) {
            atal_log("Exception: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }

    return [
        'imported' => $imported,
        'errors' => $errors,
    ];
}

function atal_import_single_yacht($yacht_data)
{
    $post_type = $yacht_data['type'] === 'new' ? 'new_yachts' : 'used_yachts';
    $source_id = $yacht_data['source_id'];
    $state = $yacht_data['state'] ?? 'draft';

    atal_log("Yacht State: $state");

    // Only import published yachts
    if ($state !== 'published') {
        atal_log("Skipping draft yacht");
        return false;
    }

    $translation_ids = [];

    // Get available translations from API
    if (!isset($yacht_data['translations'])) {
        atal_log("No translations found for yacht");
        return false;
    }

    $available_langs = array_keys($yacht_data['translations']);
    atal_log("Available languages: " . implode(', ', $available_langs));

    // Get field definitions to know which fields are images/files
    $field_groups = get_option('atal_sync_field_definitions');
    $field_types = [];

    if (!empty($field_groups)) {
        foreach ($field_groups as $group) {
            foreach ($group['fields'] as $field) {
                $field_types[$field['name']] = $field['type'];
            }
        }
    } else {
        atal_log("Warning: No field definitions found. Run 'Sync Fields' first.");
    }

    // Import for each language
    foreach ($available_langs as $lang) {
        atal_log("Importing language: $lang");

        if (!isset($yacht_data['translations'][$lang])) {
            continue;
        }

        $translation = $yacht_data['translations'][$lang];

        // Check if post already exists for this language
        $existing_posts = get_posts([
            'post_type' => $post_type,
            'meta_key' => '_atal_source_id',
            'meta_value' => $source_id,
            'posts_per_page' => -1, // Get all matches
            'post_status' => 'any',
        ]);

        $post_id = 0;

        foreach ($existing_posts as $p) {
            $p_lang = function_exists('pll_get_post_language') ? pll_get_post_language($p->ID) : 'en';
            if ($p_lang === $lang) {
                $post_id = $p->ID;
                break;
            }
        }

        if ($post_id) {
            // Update existing post
            atal_log("Updating existing post: $post_id ($lang)");
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $translation['title'],
                'post_content' => $translation['description'] ?? '',
                'post_status' => 'publish',
            ]);
        } else {
            // Create new post
            atal_log("Creating new post for language: $lang");
            $post_id = wp_insert_post([
                'post_type' => $post_type,
                'post_title' => $translation['title'],
                'post_content' => $translation['description'] ?? '',
                'post_status' => 'publish',
            ]);

            if (is_wp_error($post_id)) {
                atal_log("Error creating post: " . $post_id->get_error_message());
                throw new Exception('Failed to create post: ' . $post_id->get_error_message());
            }

            // Set source ID meta
            update_post_meta($post_id, '_atal_source_id', $source_id);
        }

        // Set language in Polylang (Always ensure language is set, even for updates)
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $lang);
        }

        // Save ACF fields
        if (function_exists('update_field')) {
            // update_field('price', $translation['price'], $post_id);

            // Get field definitions to know which fields are images/files
            // This block was moved up to avoid re-fetching in each language loop.
            // $field_groups = get_option('atal_sync_field_definitions');
            // $field_types = [];

            // if (!empty($field_groups)) {
            //     foreach ($field_groups as $group) {
            //         foreach ($group['fields'] as $field) {
            //             $field_types[$field['name']] = $field['type'];
            //         }
            //     }
            // }

            // Save custom fields
            if (isset($translation['custom_fields'])) {
                foreach ($translation['custom_fields'] as $key => $value) {
                    // Handle debug info
                    if ($key === '_debug_configured_fields') {
                        atal_log("DEBUG: Configured fields in API: " . json_encode($value));
                        continue;
                    }

                    if (empty($value)) {
                        update_field($key, $value, $post_id);
                        continue;
                    }

                    $type = $field_types[$key] ?? 'text';
                    atal_log("Processing Field: $key | Type: $type | Value: " . (is_array($value) ? 'Array' : $value));

                    // Handle Media Fields
                    if ($type === 'image' || $type === 'file') {
                        // Value is URL, need to download and get ID
                        // Use parse_url instead of filter_var to handle special characters (e.g., č, š, ž)
                        if (is_string($value) && !empty($value) && parse_url($value, PHP_URL_SCHEME)) {
                            atal_log("Importing file/image for field: $key");
                            $attachment_id = atal_import_image($value, $post_id);
                            if ($attachment_id) {
                                atal_log("File imported. Attachment ID: $attachment_id");
                                update_field($key, $attachment_id, $post_id);
                            } else {
                                atal_log("Failed to import file for field: $key");
                            }
                        } else {
                            atal_log("Value for $key is not a valid URL");
                        }
                    } elseif ($type === 'gallery') {
                        // Value is array of URLs
                        if (is_array($value)) {
                            atal_log("Importing gallery for field: $key. Count: " . count($value));
                            $gallery_ids = atal_import_gallery($value, $post_id);
                            atal_log("Gallery imported. IDs: " . json_encode($gallery_ids) . " for post: $post_id");
                            $result = update_field($key, $gallery_ids, $post_id);
                            atal_log("Gallery field updated for: $key. Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                            if (!$result) {
                                atal_log("ERROR: update_field returned false for $key on post $post_id");
                            }
                        } else {
                            atal_log("Value for gallery $key is not an array");
                        }
                    } else {
                        // Normal text/select/etc field
                        update_field($key, $value, $post_id);
                    }
                }
            }
        }

        // Import featured image
        if (!empty($yacht_data['media']['featured_image'])) {
            $attachment_id = atal_import_image($yacht_data['media']['featured_image'], $post_id);
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        // Import gallery images
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

        // Identify taxonomy fields from definitions
        $brand_field_key = 'brand'; // Default fallback

        if (!empty($field_groups)) {
            foreach ($field_groups as $group) {
                foreach ($group['fields'] as $field) {
                    if ($field['type'] === 'taxonomy' && $field['taxonomy'] === 'yacht_brand') {
                        $brand_field_key = $field['name'];
                    }
                }
            }
        }

        // Set taxonomies - Brand → Model hierarchy in yacht_brand taxonomy
        if (isset($yacht_data['brand']) && isset($yacht_data['model'])) {
            $brand_name = $yacht_data['brand']['name'];
            $model_name = $yacht_data['model']['name'];

            atal_log("Processing Brand: $brand_name for lang: $lang");

            // Create/get brand term (parent)
            $brand_term = atal_get_or_create_term(
                $brand_name,
                'yacht_brand',
                $lang,
                0 // No parent (top-level)
            );
            atal_log("Brand Term ID: $brand_term");

            if ($brand_term) {
                atal_log("Processing Model: $model_name as child of brand $brand_name for lang: $lang");

                // Create/get model term as child of brand
                $model_term = atal_get_or_create_term(
                    $model_name,
                    'yacht_brand', // Same taxonomy!
                    $lang,
                    $brand_term // Brand is parent
                );
                atal_log("Model Term ID: $model_term (parent: $brand_term)");

                if ($model_term) {
                    // Assign post to MODEL term (child) only
                    // Brand is automatically implied via hierarchy
                    wp_set_object_terms($post_id, [(int) $model_term], 'yacht_brand');

                    // Also save to ACF field if it exists
                    if (function_exists('update_field')) {
                        update_field($brand_field_key, [(int) $model_term], $post_id);
                    }
                }
            }
        }

        $translation_ids[$lang] = $post_id;
    }

    // Link translations in Polylang
    if (function_exists('pll_save_post_translations') && count($translation_ids) > 1) {
        atal_log("Linking translations: " . implode(', ', $translation_ids));
        pll_save_post_translations($translation_ids);
    }

    return true;
}

function atal_import_image($url, $post_id)
{
    // Check if already imported
    $existing = get_posts([
        'post_type' => 'attachment',
        'meta_key' => '_atal_source_url',
        'meta_value' => $url,
        'posts_per_page' => 1,
    ]);

    if (!empty($existing)) {
        return $existing[0]->ID;
    }

    // Download image
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // atal_log("Downloading image: $url");
    $tmp = download_url($url);

    if (is_wp_error($tmp)) {
        atal_log("Download failed: " . $tmp->get_error_message());
        return false;
    }

    $file_array = [
        'name' => basename($url),
        'tmp_name' => $tmp,
    ];

    // Use 0 as post_id to make media NOT attached to specific post
    // This allows media to be shared across translations (EN, SL posts)
    $attachment_id = media_handle_sideload($file_array, 0);

    if (is_wp_error($attachment_id)) {
        atal_log("Sideload failed: " . $attachment_id->get_error_message());
        @unlink($tmp);
        return false;
    }

    // Save source URL
    update_post_meta($attachment_id, '_atal_source_url', $url);

    return $attachment_id;
}

function atal_import_gallery($urls, $post_id)
{
    $attachment_ids = [];

    foreach ($urls as $url) {
        $attachment_id = atal_import_image($url, $post_id);
        if ($attachment_id) {
            $attachment_ids[] = $attachment_id;
        }
    }

    return $attachment_ids;
}

function atal_get_or_create_term($name, $taxonomy, $lang, $parent_id = 0)
{
    // Try to find existing term by name first (ignoring language initially)
    $term = get_term_by('name', $name, $taxonomy);

    if ($term) {
        $term_id = $term->term_id;
        // Check if it has the correct language
        $term_lang = function_exists('pll_get_term_language') ? pll_get_term_language($term_id) : 'en';

        if ($term_lang === $lang) {
            // If parent is specified, update term to have correct parent
            if ($parent_id && $term->parent != $parent_id) {
                wp_update_term($term_id, $taxonomy, ['parent' => $parent_id]);
            }
            return $term_id;
        }

        // If it exists but has different language, we might need a translation
        // Check if translation exists
        if (function_exists('pll_get_term')) {
            $translated_id = pll_get_term($term_id, $lang);
            if ($translated_id) {
                // Update parent if needed
                if ($parent_id) {
                    wp_update_term($translated_id, $taxonomy, ['parent' => $parent_id]);
                }
                return $translated_id;
            }
        }
    }

    // If not found or not in correct language, try to create it
    // Note: WordPress doesn't allow duplicate names in same taxonomy usually, 
    // but Polylang allows it if they are in different languages.
    // However, sometimes it's safer to append lang code to slug if collision happens.

    $args = [];
    if ($parent_id) {
        $args['parent'] = $parent_id;
    }
    if ($lang !== 'en') {
        // $args['slug'] = sanitize_title($name . '-' . $lang); 
    }

    $new_term = wp_insert_term($name, $taxonomy, $args);

    if (is_wp_error($new_term)) {
        // If error is "Term already exists", try to get it
        if (isset($new_term->error_data['term_exists'])) {
            $existing_id = (int) $new_term->error_data['term_exists'];
            // Ensure language is set for this existing term
            if (function_exists('pll_set_term_language')) {
                pll_set_term_language($existing_id, $lang);
            }
            // Update parent if needed
            if ($parent_id) {
                wp_update_term($existing_id, $taxonomy, ['parent' => $parent_id]);
            }
            return $existing_id;
        }
        atal_log("Error creating term '$name': " . $new_term->get_error_message());
        return 0;
    }

    $term_id = $new_term['term_id'];

    // Set language in Polylang
    if (function_exists('pll_set_term_language')) {
        pll_set_term_language($term_id, $lang);
    }

    return $term_id;
}

function atal_import_brands()
{
    return ['imported' => 0]; // Placeholder
}

function atal_import_models()
{
    return ['imported' => 0]; // Placeholder
}

function atal_import_news($data)
{
    atal_log("Starting News Import: " . ($data['slug'] ?? 'unknown'));

    if (empty($data['slug']) || empty($data['title'])) {
        return ['error' => 'Missing slug or title'];
    }

    $slug = $data['slug'];
    $titles = $data['title'];
    $contents = $data['content'] ?? [];
    $excerpts = $data['excerpt'] ?? [];
    $published_at = $data['published_at'];
    $featured_image_url = $data['featured_image'];

    $translation_ids = [];
    $imported = 0;

    // Get available languages from the data
    $languages = array_keys($titles);

    foreach ($languages as $lang) {
        atal_log("Processing News ($lang): $slug");

        // Check if post already exists
        $existing_posts = get_posts([
            'post_type' => 'news',
            'meta_key' => '_atal_news_slug',
            'meta_value' => $slug,
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $post_id = 0;

        foreach ($existing_posts as $p) {
            $p_lang = function_exists('pll_get_post_language') ? pll_get_post_language($p->ID) : 'en';
            if ($p_lang === $lang) {
                $post_id = $p->ID;
                break;
            }
        }

        $post_data = [
            'post_title' => $titles[$lang] ?? '',
            'post_content' => $contents[$lang] ?? '',
            'post_excerpt' => $excerpts[$lang] ?? '',
            'post_status' => 'publish',
            'post_type' => 'news',
            'post_date' => $published_at ? date('Y-m-d H:i:s', strtotime($published_at)) : date('Y-m-d H:i:s'),
            'post_name' => $slug, // WordPress will handle duplicates by appending suffix if needed, but we want to keep it clean
        ];

        if ($post_id) {
            // Update
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            atal_log("Updated News ID: $post_id");
        } else {
            // Create
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id)) {
                atal_log("Error creating news: " . $post_id->get_error_message());
                continue;
            }
            update_post_meta($post_id, '_atal_news_slug', $slug);
            atal_log("Created News ID: $post_id");
            $imported++;
        }

        // Set Language
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $lang);
        }

        // Set Featured Image
        if ($featured_image_url) {
            $attachment_id = atal_import_image($featured_image_url, $post_id);
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        // Handle Custom Fields
        if (!empty($data['custom_fields'])) {
            // Get field definitions to know types
            $field_groups = get_option('atal_sync_field_definitions');
            $field_types = [];
            if (!empty($field_groups)) {
                foreach ($field_groups as $group) {
                    foreach ($group['fields'] as $field) {
                        $field_types[$field['name']] = $field['type'];
                    }
                }
            }

            foreach ($data['custom_fields'] as $key => $value) {
                // If value is a multilingual array, extract the value for current language
                if (is_array($value) && isset($value[$lang])) {
                    $value = $value[$lang];
                }

                // Skip if value is empty
                if (empty($value)) {
                    if (function_exists('update_field')) {
                        update_field($key, $value, $post_id);
                    }
                    continue;
                }

                $type = $field_types[$key] ?? 'text';

                if (function_exists('update_field')) {
                    if ($type === 'image' || $type === 'file') {
                        if (is_string($value) && !empty($value) && parse_url($value, PHP_URL_SCHEME)) {
                            atal_log("Importing file/image for field: $key");
                            $attachment_id = atal_import_image($value, $post_id);
                            if ($attachment_id) {
                                atal_log("File imported. Attachment ID: $attachment_id");
                                update_field($key, $attachment_id, $post_id);
                            }
                        }
                    } elseif ($type === 'gallery') {
                        if (is_array($value)) {
                            atal_log("Importing gallery for field: $key. Count: " . count($value));
                            $gallery_ids = atal_import_gallery($value, $post_id);
                            update_field($key, $gallery_ids, $post_id);
                        }
                    } else {
                        atal_log("Updating field: $key | Type: $type | Value: " . (is_string($value) ? substr($value, 0, 50) : 'array'));
                        update_field($key, $value, $post_id);
                    }
                }
            }
        }

        $translation_ids[$lang] = $post_id;
    }

    // Link translations
    if (function_exists('pll_save_post_translations') && count($translation_ids) > 1) {
        pll_save_post_translations($translation_ids);
    }

    return [
        'imported' => $imported,
        'errors' => [],
    ];
}

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

    // Always sync fields first to ensure ACF field definitions are up-to-date
    atal_log("Syncing fields first...");
    $fields_result = atal_import_fields();
    if (isset($fields_result['error'])) {
        atal_log("Field sync failed: " . $fields_result['error']);
        // Continue anyway, but log the error
    } else {
        atal_log("Fields synced successfully: " . ($fields_result['imported'] ?? 0) . " fields");
    }

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
    $imported_source_ids = []; // Track which yachts we've seen from the API

    foreach ($data['yachts'] as $yacht_data) {
        try {
            atal_log("Processing yacht: " . ($yacht_data['id'] ?? 'unknown'));
            $source_id = $yacht_data['source_id'] ?? null;
            if ($source_id) {
                $imported_source_ids[] = $source_id;
            }

            $result = atal_import_single_yacht($yacht_data);
            if ($result) {
                $imported++;
            }
        } catch (Exception $e) {
            atal_log("Exception: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }

    // Cleanup: Delete WordPress posts for yachts that no longer exist in the API
    atal_log("Starting cleanup of deleted yachts...");
    $deleted_count = atal_cleanup_deleted_yachts($imported_source_ids);
    atal_log("Cleanup complete. Deleted $deleted_count yachts.");

    return [
        'imported' => $imported,
        'errors' => $errors,
        'deleted' => $deleted_count,
    ];
}

function atal_import_single_yacht($yacht_data)
{
    $post_type = $yacht_data['type'] === 'new' ? 'new_yachts' : 'used_yachts';
    $source_id = $yacht_data['source_id'];
    $state = $yacht_data['state'] ?? 'draft';

    atal_log("Yacht State: $state");

    // Determine WordPress post status based on Filament state
    $post_status = 'draft'; // Default
    if ($state === 'published') {
        $post_status = 'publish';
    } elseif ($state === 'draft' || $state === 'disabled') {
        $post_status = 'draft';
        atal_log("Yacht is draft/disabled - will be set to draft on WordPress");
    }

    $translation_ids = [];

    // Get available translations from API
    if (!isset($yacht_data['translations'])) {
        atal_log("No translations found for yacht");
        return false;
    }

    $available_langs = array_keys($yacht_data['translations']);

    // Filter languages based on active site languages
    $active_languages = atal_get_active_languages();
    $available_langs = array_intersect($available_langs, $active_languages);

    if (empty($available_langs)) {
        atal_log("No matching languages found for this site. Active: " . implode(', ', $active_languages));
        return false;
    }

    // --- Brand and Model Filtering Logic ---
    $allowed_brands = get_option('atal_sync_allowed_brands', []);
    if (!is_array($allowed_brands))
        $allowed_brands = [];

    $allowed_models = get_option('atal_sync_allowed_models', []);
    if (!is_array($allowed_models))
        $allowed_models = [];

    // Only filter if there are restrictions set
    if (!empty($allowed_brands) || !empty($allowed_models)) {
        $yacht_brand_id = $yacht_data['brand']['id'] ?? null;
        $yacht_model_id = $yacht_data['model']['id'] ?? null;

        $allow_import = false;

        // 1. Check Model Allowlist
        if ($yacht_model_id && in_array($yacht_model_id, $allowed_models)) {
            $allow_import = true;
            atal_log("Yacht allowed by Model ID: $yacht_model_id");
        }
        // 2. Check Brand Allowlist (only if model is NOT explicitly allowed/disallowed)
        // Logic: If brand is allowed, allow ALL models of that brand UNLESS specific models are selected?
        // No, the requirement was: "If brand is selected and no models, import all. If brand and model selected, import only model."
        // This implies:
        // - If model is in allowed_models -> ALLOW.
        // - If brand is in allowed_brands AND NO models of this brand are in allowed_models -> ALLOW.
        elseif ($yacht_brand_id && in_array($yacht_brand_id, $allowed_brands)) {
            // Check if any models of this brand are in the allowed list
            // We need to know which models belong to this brand to check this properly?
            // Or simpler: If the user selected the Brand checkbox, they want the brand.
            // If they ALSO selected specific models, they might want ONLY those models.
            // Let's stick to the user's request: "If brand is marked and NOT models, import all from brand. If brand AND model marked, import only model."

            // However, we don't easily know "if models of this brand are marked" without the full list of models.
            // But we can infer: If the current model is NOT in allowed_models, but the brand IS in allowed_brands...
            // Should we allow it?
            // Case A: User selects Brand X. No models selected. -> Import everything from Brand X.
            // Case B: User selects Brand X. Selects Model Y (of Brand X). -> Import ONLY Model Y.

            // To support Case B, if we are here (Model not in allowed_models), we must check if ANY models are selected.
            // But wait, if I select Brand A and Brand B (no models), I want all models from A and B.
            // If I select Brand A and Model A1... I probably only want A1.

            // Let's refine the logic:
            // If model is explicitly allowed -> ALLOW.
            // If brand is allowed... we need to check if "filtering by model" is active for this brand.
            // Since we don't have the full map here easily, let's assume:
            // If allowed_models is NOT EMPTY, we are in "strict model mode" for the brands that have models selected?
            // This is getting complicated.

            // Alternative interpretation of user request:
            // "Checkbox for brands and models".
            // If I check Brand, it selects the brand.
            // If I check Model, it selects the model.

            // Let's try this robust logic:
            // 1. If `allowed_models` contains the yacht's model -> ALLOW.
            // 2. If `allowed_brands` contains the yacht's brand...
            //    AND the yacht's model is NOT in `allowed_models` (already checked above)...
            //    We need to know if the user INTENDED to filter models for this brand.
            //    We can check if ANY model in `allowed_models` belongs to this brand.
            //    But we don't have that mapping here without fetching/caching it.

            // Let's use the `atal_sync_available_data` option which we cached!
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
                atal_log("Yacht allowed by Brand ID: $yacht_brand_id (no specific models restricted)");
            } else {
                atal_log("Yacht skipped: Brand allowed, but specific models are selected for this brand.");
            }
        }

        if (!$allow_import) {
            atal_log("Skipping yacht {$yacht_data['id']} - Brand/Model not allowed.");
            return false;
        }
    }

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
                'post_status' => $post_status,
            ]);
        } else {
            // Create new post
            atal_log("Creating new post for language: $lang");
            $post_id = wp_insert_post([
                'post_type' => $post_type,
                'post_title' => $translation['title'],
                'post_content' => $translation['description'] ?? '',
                'post_status' => $post_status,
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
    atal_log("Gallery import started. URLs count: " . count($urls));
    atal_log("Gallery URLs order: " . json_encode($urls));

    $attachment_ids = [];

    foreach ($urls as $index => $url) {
        atal_log("Importing gallery image #{$index}: {$url}");
        $attachment_id = atal_import_image($url, $post_id);
        if ($attachment_id) {
            $attachment_ids[] = $attachment_id;
            atal_log("Gallery image #{$index} imported with ID: {$attachment_id}");
        }
    }

    atal_log("Gallery import completed. Attachment IDs order: " . json_encode($attachment_ids));

    // ACF Gallery field reverses the order, so we reverse it back
    $reversed_ids = array_reverse($attachment_ids);
    atal_log("Gallery IDs reversed for ACF: " . json_encode($reversed_ids));

    return $reversed_ids;
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
    atal_log("Starting Brand Import...");

    $api_url = get_option('atal_sync_api_url');
    $api_key = get_option('atal_sync_api_key');

    if (empty($api_url) || empty($api_key)) {
        return ['error' => 'API URL or Key missing'];
    }

    $response = wp_remote_get($api_url . '/brands', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['brands'])) {
        return ['error' => 'Invalid API response'];
    }

    $imported = 0;
    $active_languages = atal_get_active_languages();

    foreach ($data['brands'] as $brand) {
        $brand_name = $brand['translations']['en']['name'] ?? $brand['slug']; // Fallback

        // Import for each language
        foreach ($active_languages as $lang) {
            // Create/Update Term
            $term_id = atal_get_or_create_term(
                $brand_name,
                'yacht_brand',
                $lang,
                0
            );

            if ($term_id) {
                // Handle Logo Import
                if (!empty($brand['logo'])) {
                    $attachment_id = atal_import_image($brand['logo'], 0); // 0 = unattached
                    if ($attachment_id) {
                        // Save as term meta
                        // Standard WP way for term meta (since 4.4)
                        update_term_meta($term_id, 'brand_logo', $attachment_id);

                        // Also try ACF way if available (usually saves to wp_options or term meta depending on version)
                        if (function_exists('update_field')) {
                            update_field('brand_logo', $attachment_id, 'yacht_brand_' . $term_id);
                        }

                        atal_log("Updated logo for brand '$brand_name' ($lang). Attachment ID: $attachment_id");
                    }
                }
            }
        }
        $imported++;
    }

    return [
        'imported' => $imported,
        'message' => "Successfully synced $imported brands."
    ];
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

    // Filter languages based on active site languages
    $active_languages = atal_get_active_languages();
    $languages = array_intersect($languages, $active_languages);

    if (empty($languages)) {
        atal_log("No matching languages found for this site. Active: " . implode(', ', $active_languages));
        return ['error' => 'No matching languages found'];
    }

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

/**
 * Get active languages on the site
 * 
 * @return array List of language codes (slugs)
 */
function atal_get_active_languages()
{
    // Check for manual override first
    $allowed = get_option('atal_sync_allowed_languages');
    if (!empty($allowed)) {
        $langs = array_map('trim', explode(',', $allowed));
        return array_filter($langs); // Remove empty values
    }

    if (function_exists('pll_languages_list')) {
        return pll_languages_list(['fields' => 'slug']);
    }

    // Fallback to site locale (first 2 chars)
    $locale = get_locale();
    $lang_code = substr($locale, 0, 2);

    return [$lang_code];
}

function atal_cleanup_deleted_yachts($imported_source_ids)
{
    atal_log("Cleanup: Checking for yachts to delete...");

    $deleted_count = 0;

    // Get all yacht posts from both post types
    $post_types = ['new_yachts', 'used_yachts'];

    foreach ($post_types as $post_type) {
        $all_yachts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_atal_source_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($all_yachts as $yacht) {
            $source_id = get_post_meta($yacht->ID, '_atal_source_id', true);

            // If this yacht's source_id is not in the imported list, delete it
            if (!in_array($source_id, $imported_source_ids)) {
                atal_log("Deleting yacht (source_id: $source_id, post_id: {$yacht->ID}) - no longer exists in API");

                // Delete all translations if Polylang is active
                if (function_exists('pll_get_post_translations')) {
                    $translations = pll_get_post_translations($yacht->ID);
                    foreach ($translations as $lang => $trans_id) {
                        wp_delete_post($trans_id, true); // true = force delete (skip trash)
                        atal_log("Deleted translation (lang: $lang, post_id: $trans_id)");
                    }
                } else {
                    // No Polylang, just delete the post
                    wp_delete_post($yacht->ID, true);
                }

                $deleted_count++;
            }
        }
    }

    return $deleted_count;
}

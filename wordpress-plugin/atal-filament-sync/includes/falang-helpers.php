<?php
/**
 * Falang Integration Helpers
 * 
 * Helper functions for Falang multilingual support
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get active languages from Falang
 * 
 * @return array Array of language codes (e.g., ['sl', 'en', 'de'])
 */
function atal_get_active_languages_falang()
{
    if (!class_exists('Falang\Core\Falang')) {
        atal_log('ERROR: Falang plugin not active');
        return ['sl']; // Fallback to default language
    }

    try {
        $falang = Falang\Core\Falang::instance();
        $languages = $falang->get_languages_list();

        $lang_codes = [];
        foreach ($languages as $lang) {
            $lang_codes[] = $lang->slug;
        }

        atal_log('Active Falang languages: ' . implode(', ', $lang_codes));
        return $lang_codes;
    } catch (Exception $e) {
        atal_log('ERROR getting Falang languages: ' . $e->getMessage());
        return ['sl']; // Fallback
    }
}

/**
 * Get default language from Falang
 * 
 * @return string Default language code (e.g., 'sl')
 */
function atal_get_default_language_falang()
{
    if (!class_exists('Falang\Core\Falang')) {
        return 'sl';
    }

    try {
        $falang = Falang\Core\Falang::instance();
        $default = $falang->get_default_language();
        return $default->slug ?? 'sl';
    } catch (Exception $e) {
        atal_log('ERROR getting default language: ' . $e->getMessage());
        return 'sl';
    }
}

/**
 * Save translation for a field via Falang
 * 
 * @param int $post_id Post ID
 * @param string $lang Language code (e.g., 'en', 'de')
 * @param string $field_name Field name (meta key, NOT ACF key)
 * @param mixed $value Field value
 * @param string $field_type 'core' for WP core fields, 'acf' for custom fields
 * @return bool Success
 */
function atal_save_translation_falang($post_id, $lang, $field_name, $value, $field_type = 'acf')
{
    // Skip if this is the default language (already saved via update_post/update_field)
    $default_lang = atal_get_default_language_falang();
    if ($lang === $default_lang) {
        return true;
    }

    if (!class_exists('Falang\Model\Falang_Model')) {
        atal_log("ERROR: Falang Model class not found");
        return false;
    }

    try {
        // 1. Get Locale from Slug (e.g., 'en' -> 'en_US')
        $model = new Falang\Model\Falang_Model();
        $language = $model->get_language_by_slug($lang);

        if (!$language || empty($language->locale)) {
            atal_log("ERROR: Could not find locale for language slug: $lang");
            return false;
        }

        $locale = $language->locale;

        // 2. Construct Meta Key Prefix (e.g., '_en_US_')
        // We use the logic found in Falang_Core::create_prefix
        $prefix = '_' . $locale . '_';

        // 3. Construct Full Meta Key
        $meta_key = $prefix . $field_name;

        // 4. Update Post Meta
        // Falang stores translations directly in wp_postmeta with the prefix
        $result = update_post_meta($post_id, $meta_key, $value);

        // 5. Mark as Published for this language
        // Falang requires a '_locale_published' meta key set to 1
        update_post_meta($post_id, $prefix . 'published', 1);

        if ($result !== false) {
            // atal_log("Saved translation: $meta_key"); // Uncomment for verbose logging
            return true;
        } else {
            // update_post_meta returns false if value is unchanged, which is fine
            return true;
        }

    } catch (Exception $e) {
        atal_log("ERROR saving translation: " . $e->getMessage());
        return false;
    }
}

/**
 * Save all translations for a post
 * 
 * @param int $post_id Post ID
 * @param array $translations_data Translations array from API
 * @param array $multilingual_fields List of field names that should be translated
 * @return bool Success
 */
function atal_save_all_translations($post_id, $translations_data, $multilingual_fields)
{
    $default_lang = atal_get_default_language_falang();
    $success = true;

    foreach ($translations_data as $lang => $translation) {
        // Skip default language (already saved)
        if ($lang === $default_lang) {
            continue;
        }

        atal_log("Saving translations for language: $lang");

        // Save core WordPress fields
        if (!empty($translation['title'])) {
            $success = atal_save_translation_falang($post_id, $lang, 'post_title', $translation['title'], 'core') && $success;

            // Also save slug (post_name)
            $slug = sanitize_title($translation['title']);
            $success = atal_save_translation_falang($post_id, $lang, 'post_name', $slug, 'core') && $success;
        }

        if (!empty($translation['description'])) {
            $success = atal_save_translation_falang($post_id, $lang, 'post_content', $translation['description'], 'core') && $success;
        } else {
            atal_log("WARNING: Description is empty for language $lang (Post ID: $post_id)");
        }

        // Save custom fields (only multilingual ones)
        if (isset($translation['custom_fields'])) {
            foreach ($translation['custom_fields'] as $field_name => $value) {
                // Only save if field is in multilingual list
                if (in_array($field_name, $multilingual_fields)) {
                    $success = atal_save_translation_falang($post_id, $lang, $field_name, $value, 'acf') && $success;
                }
            }
        }
    }

    return $success;
}

/**
 * Register translatable fields in Falang Pro
 * 
 * Auto-detects multilingual fields from field definitions
 * and registers them with Falang Pro for each CPT
 */
function atal_register_falang_fields()
{
    atal_log('Registering Falang Pro translatable fields...');

    // Get field definitions from cache
    $field_groups = get_option('atal_sync_field_definitions');

    if (empty($field_groups)) {
        atal_log('WARNING: No field definitions found. Run "Sync Field Definitions" first.');
        return;
    }

    // Get existing Falang options (preserve other settings)
    $opt = get_option('falang');
    if (!is_array($opt)) {
        $opt = [];
    }

    // Ensure post_type array exists
    if (!isset($opt['post_type'])) {
        $opt['post_type'] = [];
    }

    // Process each post type
    $total_fields = 0;
    $excluded_fields = ['brand', 'model', 'gallery_exterior', 'gallery_interior', 'featured_image', 'video_url'];

    foreach ($field_groups as $post_type => $group_data) {
        atal_log("Processing post type: $post_type");

        // Initialize post type settings if not exists
        if (!isset($opt['post_type'][$post_type])) {
            $opt['post_type'][$post_type] = [];
        }

        // Mark as translatable
        $opt['post_type'][$post_type]['translatable'] = 1;

        // Set core WordPress fields
        $opt['post_type'][$post_type]['fields'] = [
            'post_title',
            'post_name',
            'post_content',
            'post_excerpt'
        ];

        // Auto-detect multilingual custom fields (meta_keys)
        $meta_keys = [];
        foreach ($group_data['fields'] as $field) {
            $field_name = $field['name'];

            // Skip excluded fields
            if (in_array($field_name, $excluded_fields)) {
                continue;
            }

            // Only register text-based fields for translation
            if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                $meta_keys[] = $field_name;
                $total_fields++;

                atal_log("  - Registered multilingual field: $field_name (type: {$field['type']})");
            }
        }

        // Set meta_keys for this post type
        $opt['post_type'][$post_type]['meta_keys'] = $meta_keys;

        atal_log("Post type '$post_type' registered with " . count($meta_keys) . " multilingual fields");
    }

    // Save updated Falang options
    update_option('falang', $opt);

    atal_log("Falang Pro field registration complete. Total multilingual fields: $total_fields");
}

/**
 * Get list of multilingual field names
 * 
 * @return array Array of field names that should be translated
 */
function atal_get_multilingual_fields()
{
    $field_groups = get_option('atal_sync_field_definitions');

    if (empty($field_groups)) {
        return [];
    }

    $multilingual_fields = [];
    $excluded_fields = ['brand', 'model', 'video_url'];

    foreach ($field_groups as $post_type => $group_data) {
        foreach ($group_data['fields'] as $field) {
            // Skip excluded fields
            if (in_array($field['name'], $excluded_fields)) {
                continue;
            }

            // Only text-based fields are multilingual
            if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                $multilingual_fields[] = $field['name'];
            }
        }
    }

    return $multilingual_fields;
}

/**
 * Check if Falang is active and properly configured
 * 
 * @return bool True if Falang is ready
 */
function atal_is_falang_active()
{
    if (!class_exists('Falang\Core\Falang')) {
        return false;
    }

    $languages = atal_get_active_languages_falang();
    return count($languages) > 0;
}

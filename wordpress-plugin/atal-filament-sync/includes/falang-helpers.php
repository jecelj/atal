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
    if (!class_exists('Falang\Core\Translation')) {
        atal_log("ERROR: Falang Translation class not found");
        return false;
    }

    // Skip if this is the default language (already saved via update_post/update_field)
    $default_lang = atal_get_default_language_falang();
    if ($lang === $default_lang) {
        atal_log("Skipping translation save for default language: $lang");
        return true;
    }

    try {
        atal_log("Saving Falang translation: post=$post_id, lang=$lang, field=$field_name, type=$field_type");

        // Falang API: save_translation($post_id, $lang, $field, $value)
        $result = Falang\Core\Translation::save_translation($post_id, $lang, $field_name, $value);

        if ($result) {
            atal_log("Translation saved successfully");
        } else {
            atal_log("WARNING: Translation save returned false");
        }

        return $result;
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
        }

        if (!empty($translation['description'])) {
            $success = atal_save_translation_falang($post_id, $lang, 'post_content', $translation['description'], 'core') && $success;
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
 * Register translatable fields in Falang
 * 
 * Auto-detects multilingual fields from field definitions
 * and registers them with Falang
 */
function atal_register_falang_fields()
{
    atal_log('Registering Falang translatable fields...');

    // Get field definitions from cache
    $field_groups = get_option('atal_sync_field_definitions');

    if (empty($field_groups)) {
        atal_log('WARNING: No field definitions found. Run "Sync Field Definitions" first.');
        return;
    }

    $falang_fields = [
        // WordPress core fields
        'post_title' => ['type' => 'core'],
        'post_content' => ['type' => 'core'],
        'post_excerpt' => ['type' => 'core'],
    ];

    // Auto-detect multilingual custom fields
    $multilingual_count = 0;
    foreach ($field_groups as $post_type => $group_data) {
        foreach ($group_data['fields'] as $field) {
            // Only register text-based fields for translation
            if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                // CRITICAL: Use field 'name' (meta key), NOT 'key' (ACF internal)
                $field_name = $field['name'];
                $falang_fields[$field_name] = ['type' => 'acf'];
                $multilingual_count++;

                atal_log("Registered multilingual field: $field_name (type: {$field['type']})");
            }
        }
    }

    // Save to Falang settings
    update_option('falang_fields', $falang_fields);

    atal_log("Falang field registration complete. Total multilingual fields: $multilingual_count");
    atal_log("Registered fields: " . implode(', ', array_keys($falang_fields)));
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

    foreach ($field_groups as $post_type => $group_data) {
        foreach ($group_data['fields'] as $field) {
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

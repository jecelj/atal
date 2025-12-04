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
    if (!class_exists('Falang\Core\Falang')) {
        atal_log("ERROR: Falang not found");
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

        global $wpdb;
        $table = $wpdb->prefix . 'falang_translations';

        // Get language ID
        $falang = \Falang\Core\Falang::instance();
        $languages = $falang->get_languages_list();
        $lang_id = null;

        foreach ($languages as $language) {
            if ($language->slug === $lang) {
                $lang_id = $language->term_id;
                break;
            }
        }

        if (!$lang_id) {
            atal_log("ERROR: Language ID not found for: $lang");
            return false;
        }

        // Determine field type for Falang
        $falang_type = ($field_type === 'core') ? 'post' : 'post_meta';

        // Check if translation exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE object_id = %d AND language_id = %d AND field_name = %s AND field_type = %s",
            $post_id,
            $lang_id,
            $field_name,
            $falang_type
        ));

        if ($existing) {
            // Update existing translation
            $wpdb->update(
                $table,
                [
                    'field_value' => $value,
                    'published' => 1  // Set published status
                ],
                [
                    'object_id' => $post_id,
                    'language_id' => $lang_id,
                    'field_name' => $field_name,
                    'field_type' => $falang_type
                ],
                ['%s', '%d'],
                ['%d', '%d', '%s', '%s']
            );
            atal_log("Translation updated successfully");
        } else {
            // Insert new translation
            $wpdb->insert(
                $table,
                [
                    'object_id' => $post_id,
                    'language_id' => $lang_id,
                    'field_name' => $field_name,
                    'field_type' => $falang_type,
                    'field_value' => $value,
                    'published' => 1  // Set published status
                ],
                ['%d', '%d', '%s', '%s', '%s', '%d']
            );
            atal_log("Translation inserted successfully");
        }

        return true;
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
 * and registers them with Falang (both systems)
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

    if (!class_exists('Falang\Core\Falang')) {
        atal_log('ERROR: Falang not active, cannot register fields');
        return;
    }

    // Get Falang model manager
    $model_manager = \Falang\Core\Falang::instance()->get_model('post_meta_model');

    if (!$model_manager) {
        atal_log('ERROR: Could not get Falang post_meta_model');
        return;
    }

    // Auto-detect multilingual custom fields
    $multilingual_count = 0;
    $registered_fields = [];

    foreach ($field_groups as $post_type => $group_data) {
        foreach ($group_data['fields'] as $field) {
            // Only register text-based fields for translation
            if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                // CRITICAL: Use field 'name' (meta key), NOT 'key' (ACF internal)
                $field_name = $field['name'];

                // Register field with Falang
                try {
                    // Add to Falang translatable post meta
                    $model_manager->add_translatable_field($field_name);
                    $registered_fields[] = $field_name;
                    $multilingual_count++;

                    atal_log("Registered multilingual field: $field_name (type: {$field['type']})");
                } catch (Exception $e) {
                    atal_log("ERROR registering field $field_name: " . $e->getMessage());
                }
            }
        }
    }

    atal_log("Falang field registration complete. Total multilingual fields: $multilingual_count");
    atal_log("Registered fields: " . implode(', ', $registered_fields));

    // CRITICAL: Also register falang_postmeta for UI display
    atal_register_falang_postmeta();
}

/**
 * Register Falang Post Meta configuration
 * 
 * This configures which fields appear in Falang UI and associates them with CPTs.
 * Separate from falang_fields - this is for UI display and CPT association.
 */
function atal_register_falang_postmeta()
{
    atal_log('Registering Falang post meta configuration...');

    // Get field definitions from cache
    $field_groups = get_option('atal_sync_field_definitions');

    if (empty($field_groups)) {
        atal_log('WARNING: No field definitions found for postmeta registration.');
        return;
    }

    // Get existing falang_postmeta or create new
    $postmeta = get_option('falang_postmeta', []);

    // Ensure it's an array
    if (!is_array($postmeta)) {
        $postmeta = [];
    }

    $total_registered = 0;

    // Register fields per CPT
    foreach ($field_groups as $post_type => $group_data) {
        // Initialize CPT structure if not exists
        if (!isset($postmeta[$post_type])) {
            $postmeta[$post_type] = [
                'meta_keys' => [],
                'active' => '1'
            ];
        }

        // Ensure meta_keys exists
        if (!isset($postmeta[$post_type]['meta_keys'])) {
            $postmeta[$post_type]['meta_keys'] = [];
        }

        $cpt_fields = 0;

        foreach ($group_data['fields'] as $field) {
            // Only register text-based fields for translation
            if (in_array($field['type'], ['text', 'textarea', 'wysiwyg'])) {
                // CRITICAL: Use field 'name' (meta key), NOT 'key' (ACF internal)
                $meta_key = $field['name'];

                // Register field for this CPT (value = '1' as string)
                $postmeta[$post_type]['meta_keys'][$meta_key] = '1';
                $cpt_fields++;
                $total_registered++;

                atal_log("Registered postmeta: CPT=$post_type, field=$meta_key");
            }
        }

        atal_log("Registered $cpt_fields fields for CPT: $post_type");
    }

    // Save to database
    update_option('falang_postmeta', $postmeta);

    atal_log("Falang postmeta registration complete. Total fields: $total_registered");
    atal_log("Configured CPTs: " . implode(', ', array_keys($postmeta)));
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

<?php
/**
 * Plugin Name: Atal Used Yachts Sync
 * Description: Syncs used yachts from Atal Master system to WordPress using ACF
 * Version: 1.0.0
 * Author: Atal Yachts
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ATAL_USED_YACHTS_SYNC_VERSION', '1.0.0');
define('ATAL_USED_YACHTS_SYNC_PATH', plugin_dir_path(__FILE__));
define('ATAL_USED_YACHTS_SYNC_URL', plugin_dir_url(__FILE__));

// Check for ACF
add_action('admin_init', function () {
    if (!class_exists('ACF')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Atal Used Yachts Sync</strong> requires Advanced Custom Fields Pro to be installed and activated.</p></div>';
        });
        deactivate_plugins(plugin_basename(__FILE__));
    }
});

// Include files
require_once ATAL_USED_YACHTS_SYNC_PATH . 'includes/cpt-register.php';
require_once ATAL_USED_YACHTS_SYNC_PATH . 'includes/acf-register.php';
require_once ATAL_USED_YACHTS_SYNC_PATH . 'includes/rest-api.php';
require_once ATAL_USED_YACHTS_SYNC_PATH . 'includes/importer.php';
require_once ATAL_USED_YACHTS_SYNC_PATH . 'includes/settings.php';

// Activation hook
register_activation_hook(__FILE__, function () {
    // Flush rewrite rules
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

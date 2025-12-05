<?php
/**
 * Plugin Name: Kreativne komunikacije - Yachts & News Sync
 * Plugin URI: https://atal.at
 * Description: Syncs yachts and news from Filament Admin to WordPress with Falang multilingual support
 * Version: 2.0.0
 * Author: Kreativne komunikacije
 * Text Domain: kk-sync
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ATAL_SYNC_VERSION', '2.0.0');
define('ATAL_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ATAL_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include files
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/settings.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/cpt-register.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/taxonomy-register.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/scf-register.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/falang-helpers.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/importer.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/rest-api.php';

// Activation hook
register_activation_hook(__FILE__, 'atal_sync_activate');

// Force Falang field registration on admin init
add_action('admin_init', 'atal_register_falang_fields', 999);


function atal_sync_activate()
{
    // Flush rewrite rules
    flush_rewrite_rules();

    // Set default options
    if (!get_option('atal_sync_api_url')) {
        update_option('atal_sync_api_url', '');
    }
    if (!get_option('atal_sync_api_key')) {
        update_option('atal_sync_api_key', '');
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'atal_sync_deactivate');

function atal_sync_deactivate()
{
    flush_rewrite_rules();
}

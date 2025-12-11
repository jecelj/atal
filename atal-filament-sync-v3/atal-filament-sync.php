<?php
/**
 * Plugin Name: Kreativne komunikacije - Yachts & News Sync (Master-Push)
 * Plugin URI: https://atal.at
 * Description: Receives pushed yacht and news data from Filament Master, supports Falang and ACF.
 * Version: 3.1.0
 * Author: Kreativne komunikacije
 * Text Domain: kk-sync
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ATAL_SYNC_VERSION', '3.1.0');
define('ATAL_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include files
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/settings.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/cpt-register.php';
require_once ATAL_SYNC_PLUGIN_DIR . 'includes/class-atal-sync-api.php';

// Activation hook
register_activation_hook(__FILE__, 'atal_sync_activate');

// Init API
new Atal_Sync_API();

function atal_sync_activate()
{
    // Flush rewrite rules
    flush_rewrite_rules();

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

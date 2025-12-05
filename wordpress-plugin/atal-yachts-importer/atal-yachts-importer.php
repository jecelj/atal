<?php
/*
Plugin Name: Kreativne komunikacije - Boat Import
Description: Imports yachts from the central CMS system with Polylang support. Optimized for Secure Custom Fields (SCF).
Version: 1.8
Author: Kreativne komunikacije
*/

if (!defined('ABSPATH')) exit;

// --- Security / Global Constants ---
define('ATAL_IMPORT_API_KEY', 'a8f3e29c7b1d45fa9831c442d2e5bbf3');

// --- Includes ---
require_once plugin_dir_path(__FILE__) . 'includes/cpt-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/taxonomy-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/importer-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/rest-endpoints.php';
require_once plugin_dir_path(__FILE__) . 'includes/acf-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/debug-endpoints.php';

// =====================================================
// 1. Register all post meta keys globally for REST API
// =====================================================
// Opomba: Meta polja se registrirajo v cpt-register.php za specifične post type
// Ta globalna registracija je odveč, vendar jo obdržimo za kompatibilnost
add_action('rest_api_init', function () {
    global $wpdb;
    
    // Pridobi samo javna meta polja
    $meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE %s",
        $wpdb->esc_like('_') . '%'
    ));

    foreach ($meta_keys as $key) {
        if (empty($key)) continue;

        register_post_meta('', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => '__return_true',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }
}, 20);

<?php
/**
 * Helper funkcije za ACF podporo
 */

if (!defined('ABSPATH')) exit;

/**
 * Preveri, ali je ACF nameščen
 */
function atal_is_acf_active() {
    return function_exists('acf_get_field_groups') || function_exists('get_field');
}

// Opomba: ACF field groups se NE ustvarjajo avtomatsko
// Podatki se shranjujejo kot post meta in so dostopni brez ACF

/**
 * Avtomatsko registrira ACF polja iz obstoječih postov
 */
add_action('init', function () {
    $post_types = ['new_yachts', 'used_yachts'];
    
    foreach ($post_types as $type) {
        // Preglej poste in registriraj njihova meta polja
        $posts = get_posts([
            'post_type' => $type,
            'numberposts' => 10, // Pregledamo več postov za boljšo pokritost
            'post_status' => 'any'
        ]);
        
        if (empty($posts)) continue;

        foreach ($posts as $post) {
            $meta = get_post_meta($post->ID);
            foreach ($meta as $key => $val) {
                // Ignoriramo sistemska polja
                if (strpos($key, '_atal_') === 0 || 
                    strpos($key, '_edit_') === 0 || 
                    strpos($key, '_wp_') === 0 ||
                    $key === '_thumbnail_id') {
                    continue;
                }

                // Registriraj polje v REST API
                register_post_meta($type, $key, [
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                    'auth_callback' => '__return_true',
                ]);
            }
        }
    }
}, 20);
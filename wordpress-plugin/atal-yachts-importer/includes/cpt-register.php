<?php
/**
 * Register custom post types and auto-expose meta for REST API
 */

if (!defined('ABSPATH')) exit;

// === Register Custom Post Types ===
add_action('init', function () {

    $cpts = [
        'new_yachts' => [
            'name' => 'New Yachts',
            'singular' => 'New Yacht',
            'slug' => 'new-yachts',
        ],
        'used_yachts' => [
            'name' => 'Used Yachts',
            'singular' => 'Used Yacht',
            'slug' => 'used-yachts',
        ],
    ];

    foreach ($cpts as $type => $data) {
        register_post_type($type, [
            'label' => $data['name'],
            'labels' => [
                'name' => $data['name'],
                'singular_name' => $data['singular'],
                'add_new' => 'Add ' . $data['singular'],
                'add_new_item' => 'Add ' . $data['singular'],
                'edit_item' => 'Edit ' . $data['singular'],
                'new_item' => 'New ' . $data['singular'],
                'view_item' => 'View ' . $data['singular'],
                'search_items' => 'Search ' . $data['name'],
                'not_found' => 'No ' . strtolower($data['name']) . ' found',
                'not_found_in_trash' => 'No ' . strtolower($data['name']) . ' found in Trash',
            ],
            'public' => true,
            'publicly_queryable' => true, // POMEMBNO za YooTheme
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'rest_base' => $type, // POMEMBNO: Uporabi post_type name namesto slug za YooTheme
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'menu_icon' => 'dashicons-admin-site-alt3',
            'has_archive' => true,
            'rewrite' => ['slug' => $data['slug']],
            'can_export' => true,
            'show_in_nav_menus' => true,
        ]);
    }
});

// === Dodaj stolpec za jezik v admin seznamu ===
add_filter('manage_new_yachts_posts_columns', function($columns) {
    // Dodaj stolpec za jezik pred stolpcem "Date"
    $new_columns = [];
    foreach ($columns as $key => $value) {
        if ($key === 'date') {
            $new_columns['language'] = 'Language';
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
});

add_action('manage_new_yachts_posts_custom_column', function($column, $post_id) {
    if ($column === 'language') {
        $lang = '';
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id);
        }
        if (empty($lang)) {
            $lang = get_post_meta($post_id, 'pll_language', true);
        }
        if (empty($lang)) {
            $lang = get_post_meta($post_id, '_atal_source_lang', true);
        }
        if (!empty($lang)) {
            // Poskusi dobiti ime jezika iz Polylang
            if (function_exists('pll_the_languages')) {
                $languages = pll_the_languages(['raw' => 1]);
                foreach ($languages as $language) {
                    if ($language['slug'] === $lang) {
                        echo '<span title="' . esc_attr($language['name']) . '">' . esc_html(strtoupper($lang)) . '</span>';
                        return;
                    }
                }
            }
            echo '<span>' . esc_html(strtoupper($lang)) . '</span>';
        } else {
            echo '<span style="color:#999;">—</span>';
        }
    }
}, 10, 2);

add_filter('manage_used_yachts_posts_columns', function($columns) {
    // Dodaj stolpec za jezik pred stolpcem "Date"
    $new_columns = [];
    foreach ($columns as $key => $value) {
        if ($key === 'date') {
            $new_columns['language'] = 'Language';
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
});

add_action('manage_used_yachts_posts_custom_column', function($column, $post_id) {
    if ($column === 'language') {
        $lang = '';
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id);
        }
        if (empty($lang)) {
            $lang = get_post_meta($post_id, 'pll_language', true);
        }
        if (empty($lang)) {
            $lang = get_post_meta($post_id, '_atal_source_lang', true);
        }
        if (!empty($lang)) {
            // Poskusi dobiti ime jezika iz Polylang
            if (function_exists('pll_the_languages')) {
                $languages = pll_the_languages(['raw' => 1]);
                foreach ($languages as $language) {
                    if ($language['slug'] === $lang) {
                        echo '<span title="' . esc_attr($language['name']) . '">' . esc_html(strtoupper($lang)) . '</span>';
                        return;
                    }
                }
            }
            echo '<span>' . esc_html(strtoupper($lang)) . '</span>';
        } else {
            echo '<span style="color:#999;">—</span>';
        }
    }
}, 10, 2);

// === Auto expose ALL meta for our CPTs in REST API ===
add_action('rest_api_init', function () {
    global $wpdb;
    $post_types = ['new_yachts', 'used_yachts'];

    // Pridobi samo javna meta polja (brez sistemskih) - optimizirano za naše post type-e
    $meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_key 
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key NOT LIKE %s
         AND p.post_type IN ('used_yachts', 'new_yachts')
         AND p.post_status = 'publish'",
        $wpdb->esc_like('_') . '%'
    ));

    foreach ($meta_keys as $key) {
        if (empty($key)) continue;
        
        // Preskoči sistemska polja
        if (strpos($key, '_atal_') === 0 || 
            strpos($key, '_edit_') === 0 || 
            strpos($key, '_wp_') === 0) {
            continue;
        }
        
        // Zazni gallery polja (po imenu ali tipu)
        // Gallery polja običajno vsebujejo 'gallery' v imenu ali so array attachment ID-jev
        $is_gallery_field = (
            stripos($key, 'gallery') !== false || 
            stripos($key, 'images') !== false
        );

        foreach ($post_types as $type) {
            // Registriraj gallery polja kot array tip za YooTheme kompatibilnost
            if ($is_gallery_field) {
                register_post_meta($type, $key, [
                    'show_in_rest'      => [
                        'schema' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                    'single'            => true,
                    'type'              => 'array',
                    'auth_callback'     => '__return_true',
                    'get_callback'      => function($object_id, $meta_key) {
                        // Pridobi vrednost iz baze in jo deserializiraj
                        $value = get_post_meta($object_id, $meta_key, true);
                        
                        // Če je string (serializiran), ga deserializiraj
                        if (is_string($value) && !empty($value)) {
                            $unserialized = maybe_unserialize($value);
                            if (is_array($unserialized)) {
                                return array_map('intval', array_filter($unserialized, 'is_numeric'));
                            }
                        }
                        
                        // Če je že array, ga vrni
                        if (is_array($value)) {
                            return array_map('intval', array_filter($value, 'is_numeric'));
                        }
                        
                        return [];
                    },
                    'sanitize_callback' => function($value) {
                        // Gallery mora biti array integerjev (attachment ID-jev)
                        if (is_array($value)) {
                            return array_map('intval', array_filter($value, 'is_numeric'));
                        }
                        return [];
                    },
                ]);
                error_log("Atal REST API: Registered gallery field '{$key}' as array type with get_callback");
            } else {
                // Ostala polja registriraj kot string
                register_post_meta($type, $key, [
                    'show_in_rest'      => true,
                    'single'            => true,
                    'type'              => 'string',
                    'auth_callback'     => '__return_true',
                    'sanitize_callback' => function($value) {
                        // Omogoči tudi array vrednosti (npr. kompleksna polja)
                        if (is_array($value)) {
                            return $value;
                        }
                        return sanitize_text_field($value);
                    },
                ]);
            }
        }
    }
    
    error_log('Atal REST API: Registered ' . count($meta_keys) . ' meta keys for REST API');
}, 20);

// Dodatno: Izpostavi meta polja direktno v REST API response-u
// Simuliramo ACF to REST API strukturo - dodajamo polja tudi v 'acf' objekt
// Pomembno: Ta filter se izvede PRED yootheme-polylang-integration.php filtrom (prioriteta 10)
foreach (['new_yachts', 'used_yachts'] as $post_type) {
    add_filter("rest_prepare_{$post_type}", function ($response, $post) {
        if (!$response || !$post) {
            return $response;
        }
        
        // Pridobi obstoječe podatke (ne prepisujemo)
        $data = $response->get_data();
        $existing_meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        $existing_acf = isset($data['acf']) && is_array($data['acf']) ? $data['acf'] : [];
        
        // Pridobi vse meta polja
        $meta = get_post_meta($post->ID);
        $meta_data = $existing_meta; // Začni z obstoječimi podatki
        $acf_data = $existing_acf; // Začni z obstoječimi podatki
        
        foreach ($meta as $key => $value) {
            // Preskoči sistemska polja
            if (strpos($key, '_') === 0) continue;
            if (strpos($key, '_atal_') === 0 || 
                strpos($key, '_edit_') === 0 || 
                strpos($key, '_wp_') === 0) {
                continue;
            }
            
            // Dodaj samo če še ne obstaja
            if (!isset($meta_data[$key])) {
                if (is_array($value) && count($value) === 1) {
                    $value = $value[0];
                }
                $unserialized = maybe_unserialize($value);
                $final_value = is_array($unserialized) ? json_encode($unserialized) : $unserialized;
                
                // Dodaj v meta objekt
                $meta_data[$key] = $final_value;
                
                // Dodaj tudi v acf objekt (za ACF to REST API kompatibilnost)
                $acf_data[$key] = $final_value;
            }
        }
        
        // Posodobi meta polja v REST API response (združi z obstoječimi)
        $data['meta'] = $meta_data;
        
        // Dodaj tudi v acf objekt (za ACF to REST API kompatibilnost)
        // Pomembno: Ohranimo obstoječe ACF podatke in dodajamo nove
        $data['acf'] = array_merge($existing_acf, $acf_data);
        
        $response->set_data($data);
        
        return $response;
    }, 10, 2);
}


<?php
/**
 * Custom Post Type Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'atal_sync_register_cpts');

function atal_sync_register_cpts()
{
    // Base64 encoded yacht icon (SVG - White for Admin Menu)
    $yacht_icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJ3aGl0ZSI+PHBhdGggZD0iTTAgMGgyNHYyNEgweiIgZmlsbD0ibm9uZSIvPjxwYXRoIGQ9Ik00IDE2YzAgMi4yMSAxLjc5IDQgNCA0aDhjMi4yMSAwIDQtMS43OSA0LTR2LTFINHYxem0yLTVoMTJsLTEtNUg3bC0xIDV6bTItOC41QzggMi41IDguMSAzLjUgOSA1aDZjLjktMS41IDEtMi41IDEtMi41UzE1IDEgMTIgMSA4IDIuNSA4IDIuNXoiLz48L3N2Zz4=';

    // New Yachts CPT
    register_post_type('new_yachts', [
        'labels' => [
            'name' => __('New Yachts', 'atal-sync'),
            'singular_name' => __('New Yacht', 'atal-sync'),
            'add_new' => __('Add New', 'atal-sync'),
            'add_new_item' => __('Add New Yacht', 'atal-sync'),
            'edit_item' => __('Edit Yacht', 'atal-sync'),
            'view_item' => __('View Yacht', 'atal-sync'),
            'search_items' => __('Search Yachts', 'atal-sync'),
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'rewrite' => ['slug' => 'new-yachts'],
        'menu_icon' => $yacht_icon,
        'show_in_menu' => true,
    ]);

    // Used Yachts CPT
    register_post_type('used_yachts', [
        'labels' => [
            'name' => __('Used Yachts', 'atal-sync'),
            'singular_name' => __('Used Yacht', 'atal-sync'),
            'add_new' => __('Add New', 'atal-sync'),
            'add_new_item' => __('Add New Yacht', 'atal-sync'),
            'edit_item' => __('Edit Yacht', 'atal-sync'),
            'view_item' => __('View Yacht', 'atal-sync'),
            'search_items' => __('Search Yachts', 'atal-sync'),
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'rewrite' => ['slug' => 'used-yachts'],
        'menu_icon' => $yacht_icon,
        'show_in_menu' => true,
    ]);

    // News CPT
    register_post_type('news', [
        'labels' => [
            'name' => __('News', 'atal-sync'),
            'singular_name' => __('News', 'atal-sync'),
            'add_new' => __('Add New', 'atal-sync'),
            'add_new_item' => __('Add New News', 'atal-sync'),
            'edit_item' => __('Edit News', 'atal-sync'),
            'view_item' => __('View News', 'atal-sync'),
            'search_items' => __('Search News', 'atal-sync'),
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'rewrite' => ['slug' => 'news'],
        'menu_icon' => 'dashicons-media-document',
        'show_in_menu' => true,
    ]);
}

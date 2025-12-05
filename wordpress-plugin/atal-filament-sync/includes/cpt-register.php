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
    // Base64 encoded yacht icon (from Master)
    $yacht_icon = 'data:image/png;base64,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';

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

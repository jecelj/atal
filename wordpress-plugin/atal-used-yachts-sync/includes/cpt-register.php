<?php
/**
 * Register Used Yachts Custom Post Type
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    register_post_type('used_yacht', [
        'labels' => [
            'name' => __('Used Yachts', 'atal-used-yachts-sync'),
            'singular_name' => __('Used Yacht', 'atal-used-yachts-sync'),
            'add_new' => __('Add New', 'atal-used-yachts-sync'),
            'add_new_item' => __('Add New Used Yacht', 'atal-used-yachts-sync'),
            'edit_item' => __('Edit Used Yacht', 'atal-used-yachts-sync'),
            'new_item' => __('New Used Yacht', 'atal-used-yachts-sync'),
            'view_item' => __('View Used Yacht', 'atal-used-yachts-sync'),
            'search_items' => __('Search Used Yachts', 'atal-used-yachts-sync'),
            'not_found' => __('No used yachts found', 'atal-used-yachts-sync'),
            'not_found_in_trash' => __('No used yachts found in trash', 'atal-used-yachts-sync'),
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon' => 'dashicons-admin-multisite',
        'rewrite' => ['slug' => 'used-yachts'],
        'taxonomies' => [],
    ]);

    // Register Brand taxonomy
    register_taxonomy('yacht_brand', 'used_yacht', [
        'labels' => [
            'name' => __('Brands', 'atal-used-yachts-sync'),
            'singular_name' => __('Brand', 'atal-used-yachts-sync'),
        ],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'yacht-brand'],
    ]);

    // Register Model taxonomy
    register_taxonomy('yacht_model', 'used_yacht', [
        'labels' => [
            'name' => __('Models', 'atal-used-yachts-sync'),
            'singular_name' => __('Model', 'atal-used-yachts-sync'),
        ],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'yacht-model'],
    ]);
});

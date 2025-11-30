<?php
/**
 * Taxonomy Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'atal_sync_register_taxonomies');

function atal_sync_register_taxonomies()
{
    // Brand Taxonomy (Hierarchical: Brand → Model)
    // Brand terms are top-level, Model terms are children of Brand terms
    register_taxonomy('yacht_brand', ['new_yachts', 'used_yachts'], [
        'labels' => [
            'name' => __('Brands & Models', 'atal-sync'),
            'singular_name' => __('Brand/Model', 'atal-sync'),
            'search_items' => __('Search Brands & Models', 'atal-sync'),
            'all_items' => __('All Brands & Models', 'atal-sync'),
            'parent_item' => __('Parent Brand', 'atal-sync'),
            'parent_item_colon' => __('Parent Brand:', 'atal-sync'),
            'edit_item' => __('Edit Brand/Model', 'atal-sync'),
            'update_item' => __('Update Brand/Model', 'atal-sync'),
            'add_new_item' => __('Add New Brand/Model', 'atal-sync'),
        ],
        'hierarchical' => true,
        'show_in_rest' => true,
        'show_admin_column' => true,
        'rewrite' => ['slug' => 'brand', 'hierarchical' => true],
    ]);

    // Location Taxonomy (Non-hierarchical)
    register_taxonomy('yacht_location', ['new_yachts', 'used_yachts'], [
        'labels' => [
            'name' => __('Locations', 'atal-sync'),
            'singular_name' => __('Location', 'atal-sync'),
            'search_items' => __('Search Locations', 'atal-sync'),
            'all_items' => __('All Locations', 'atal-sync'),
            'edit_item' => __('Edit Location', 'atal-sync'),
            'update_item' => __('Update Location', 'atal-sync'),
            'add_new_item' => __('Add New Location', 'atal-sync'),
            'new_item_name' => __('New Location Name', 'atal-sync'),
        ],
        'hierarchical' => false,
        'show_in_rest' => true,
        'show_admin_column' => true,
        'rewrite' => ['slug' => 'location'],
    ]);
}

<?php
add_action('init', function () {
    $labels = [
        'name' => 'New Yacht Categories',
        'singular_name' => 'New Yacht Category',
        'search_items' => 'Search Categories',
        'all_items' => 'All Categories',
        'parent_item' => 'Parent Category',
        'parent_item_colon' => 'Parent Category:',
        'edit_item' => 'Edit Category',
        'update_item' => 'Update Category',
        'add_new_item' => 'Add New Category',
        'new_item_name' => 'New Category Name',
        'menu_name' => 'New Yacht Categories',
    ];

    register_taxonomy('new_yacht_category', 'new_yachts', [
        'hierarchical' => true,
        'labels' => $labels,
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => ['slug' => 'new-yacht-category'],
        'show_in_rest' => true,
    ]);
});

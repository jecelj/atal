<?php
/**
 * Plugin Name: Galeon Adriatic Migration
 * Description: One-time migration tool to export yachts from galeonadriatic.com to Filament master
 * Version: 1.0.0
 * Author: Kreativne komunikacije
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('GALEON_MIGRATION_VERSION', '1.0.0');
define('GALEON_MIGRATION_DIR', plugin_dir_path(__FILE__));

// Include files
require_once GALEON_MIGRATION_DIR . 'includes/smart-slider-extractor.php';
require_once GALEON_MIGRATION_DIR . 'includes/field-mapper.php';
require_once GALEON_MIGRATION_DIR . 'includes/export-handler.php';

// Add admin menu
add_action('admin_menu', 'galeon_migration_menu');

function galeon_migration_menu()
{
    add_menu_page(
        'Galeon Migration',
        'Galeon Migration',
        'manage_options',
        'galeon-migration',
        'galeon_migration_page',
        'dashicons-upload',
        100
    );
}

function galeon_migration_page()
{
    ?>
    <div class="wrap">
        <h1>Galeon Adriatic Migration</h1>
        <p>Export yachts to Filament master system</p>

        <div class="card" style="max-width: 800px;">
            <h2>Test Export - Single Yacht</h2>
            <p>Export a yacht by Post ID for testing</p>

            <form method="post" action="">
                <?php wp_nonce_field('galeon_migration_export', 'galeon_migration_nonce'); ?>
                <input type="hidden" name="action" value="test_export">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="post_id">Post ID</label>
                        </th>
                        <td>
                            <input type="number" name="post_id" id="post_id" value="837" class="regular-text" required>
                            <p class="description">Enter the WordPress Post ID of the yacht to export (default: 837 = 440
                                FLY)</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Export Yacht</button>
                </p>
            </form>
        </div>

        <?php
        if (isset($_POST['action']) && $_POST['action'] === 'test_export') {
            if (check_admin_referer('galeon_migration_export', 'galeon_migration_nonce')) {
                $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 837;
                galeon_handle_test_export($post_id);
            }
        }
        ?>
    </div>
    <?php
}

function galeon_handle_test_export($post_id = 837)
{
    echo '<div class="notice notice-info"><p>Starting export for Post ID: ' . esc_html($post_id) . '...</p></div>';

    $exporter = new Galeon_Export_Handler();
    $result = $exporter->export_single_yacht($post_id);

    if ($result['success']) {
        echo '<div class="notice notice-success"><p>Export successful!</p></div>';
        echo '<pre>' . json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
    } else {
        echo '<div class="notice notice-error"><p>Export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

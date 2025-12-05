<?php
/**
 * Plugin Name: Galeon Adriatic Migration
 * Description: One-time migration tool to export yachts from galeonadriatic.com to Filament master
 * Version: 1.0.1
 * Author: Kreativne komunikacije
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('GALEON_MIGRATION_VERSION', '1.0.1');
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

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Bulk Export - All Yachts</h2>
            <p>Export all yachts from valid categories (Explorer, Flybridge, GTO, Hardtop, Skydeck)</p>

            <form method="post" action="">
                <?php wp_nonce_field('galeon_migration_export', 'galeon_migration_nonce'); ?>
                <input type="hidden" name="action" value="bulk_export">

                <p class="submit">
                    <button type="submit" class="button button-primary">Export All Yachts</button>
                </p>
            </form>
        </div>

        <hr style="margin: 40px 0;">

        <h2>Used Yachts Export</h2>

        <div class="card" style="max-width: 800px;">
            <h2>Export ACF Field Configuration</h2>
            <p>Export the ACF field configuration for Used Yachts to import into Master system</p>

            <form method="post" action="">
                <?php wp_nonce_field('galeon_migration_export', 'galeon_migration_nonce'); ?>
                <input type="hidden" name="action" value="export_used_fields">

                <p class="submit">
                    <button type="submit" class="button button-primary">Export Field Configuration</button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Test Export - Single Used Yacht</h2>
            <p>Export a Used Yacht by Post ID for testing</p>

            <form method="post" action="">
                <?php wp_nonce_field('galeon_migration_export', 'galeon_migration_nonce'); ?>
                <input type="hidden" name="action" value="test_export_used">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="used_post_id">Post ID</label>
                        </th>
                        <td>
                            <input type="number" name="post_id" id="used_post_id" class="regular-text" required>
                            <p class="description">Enter the WordPress Post ID of the Used Yacht to export</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Export Used Yacht</button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Bulk Export - All Used Yachts</h2>
            <p>Export all Used Yachts from 'preowned-yachts' category</p>

            <form method="post" action="">
                <?php wp_nonce_field('galeon_migration_export', 'galeon_migration_nonce'); ?>
                <input type="hidden" name="action" value="bulk_export_used">

                <p class="submit">
                    <button type="submit" class="button button-primary">Export All Used Yachts</button>
                </p>
            </form>
        </div>

        <?php
        // Handle Used Yachts exports
        if (isset($_POST['action']) && $_POST['action'] === 'export_used_fields') {
            if (check_admin_referer('galeon_migration_export', 'galeon_migration_nonce')) {
                galeon_handle_used_fields_export();
            }
        }

        if (isset($_POST['action']) && $_POST['action'] === 'test_export_used') {
            if (check_admin_referer('galeon_migration_export', 'galeon_migration_nonce')) {
                $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
                galeon_handle_test_used_export($post_id);
            }
        }

        if (isset($_POST['action']) && $_POST['action'] === 'bulk_export_used') {
            if (check_admin_referer('galeon_migration_export', 'galeon_migration_nonce')) {
                galeon_handle_bulk_used_export();
            }
        }

        // Handle New Yachts exports
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_export') {
            if (check_admin_referer('galeon_migration_export', 'galeon_migration_nonce')) {
                galeon_handle_bulk_export();
            }
        }

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
        $json_output = json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?>
        <div class="notice notice-success">
            <p>Export successful!</p>
        </div>

        <div style="margin-top: 20px;">
            <button type="button" id="copy-json-btn" class="button button-primary" style="margin-bottom: 10px;">
                📋 Copy JSON to Clipboard
            </button>
            <span id="copy-status" style="margin-left: 10px; color: green; display: none;">✓ Copied!</span>
        </div>

        <pre id="json-output"
            style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"><?php echo esc_html($json_output); ?></pre>

        <script>
            document.getElementById('copy-json-btn').addEventListener('click', function () {
                const jsonText = document.getElementById('json-output').textContent;

                navigator.clipboard.writeText(jsonText).then(function () {
                    const status = document.getElementById('copy-status');
                    status.style.display = 'inline';

                    setTimeout(function () {
                        status.style.display = 'none';
                    }, 2000);
                }).catch(function (err) {
                    alert('Failed to copy: ' + err);
                });
            });
        </script>
        <?php
    } else {
        echo '<div class="notice notice-error"><p>Export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

function galeon_handle_bulk_export()
{
    echo '<div class="notice notice-info"><p>Starting bulk export of all yachts...</p></div>';

    $exporter = new Galeon_Export_Handler();
    $result = $exporter->export_all_yachts();

    if ($result['success']) {
        $json_output = json_encode($result['yachts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?>
        <div class="notice notice-success">
            <p>Bulk export successful!</p>
            <ul>
                <li>Total yachts found: <?php echo esc_html($result['total']); ?></li>
                <li>Successfully exported: <?php echo esc_html($result['exported']); ?></li>
                <li>Failed: <?php echo esc_html($result['failed']); ?></li>
            </ul>
        </div>

        <?php if (!empty($result['errors'])): ?>
            <div class="notice notice-warning">
                <p><strong>Failed exports:</strong></p>
                <ul>
                    <?php foreach ($result['errors'] as $error): ?>
                        <li><?php echo esc_html($error['title']); ?> (ID: <?php echo esc_html($error['id']); ?>) -
                            <?php echo esc_html($error['error']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <button type="button" id="copy-bulk-json-btn" class="button button-primary" style="margin-bottom: 10px;">
                📋 Copy All Yachts JSON to Clipboard
            </button>
            <span id="copy-bulk-status" style="margin-left: 10px; color: green; display: none;">✓ Copied!</span>
        </div>

        <pre id="bulk-json-output"
            style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"><?php echo esc_html($json_output); ?></pre>

        <script>
            document.getElementById('copy-bulk-json-btn').addEventListener('click', function () {
                const jsonText = document.getElementById('bulk-json-output').textContent;

                navigator.clipboard.writeText(jsonText).then(function () {
                    const status = document.getElementById('copy-bulk-status');
                    status.style.display = 'inline';

                    setTimeout(function () {
                        status.style.display = 'none';
                    }, 2000);
                }).catch(function (err) {
                    alert('Failed to copy: ' + err);
                });
            });
        </script>
        <?php
    } else {
        echo '<div class="notice notice-error"><p>Bulk export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

function galeon_handle_used_fields_export()
{
    echo '<div class="notice notice-info"><p>Exporting ACF field configuration for Used Yachts...</p></div>';

    $exporter = new Galeon_Export_Handler();
    $result = $exporter->export_used_yacht_fields();

    if ($result['success']) {
        $json_output = json_encode($result['fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?>
        <div class="notice notice-success">
            <p>Field configuration exported successfully!</p>
            <ul>
                <li>Total fields: <?php echo esc_html($result['count']); ?></li>
            </ul>
        </div>

        <div style="margin-top: 20px;">
            <button type="button" id="copy-fields-json-btn" class="button button-primary" style="margin-bottom: 10px;">
                📋 Copy Field Configuration JSON
            </button>
            <span id="copy-fields-status" style="margin-left: 10px; color: green; display: none;">✓ Copied!</span>
        </div>

        <pre id="fields-json-output"
            style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"><?php echo esc_html($json_output); ?></pre>

        <script>
            document.getElementById('copy-fields-json-btn').addEventListener('click', function () {
                const jsonText = document.getElementById('fields-json-output').textContent;

                navigator.clipboard.writeText(jsonText).then(function () {
                    const status = document.getElementById('copy-fields-status');
                    status.style.display = 'inline';

                    setTimeout(function () {
                        status.style.display = 'none';
                    }, 2000);
                }).catch(function (err) {
                    alert('Failed to copy: ' + err);
                });
            });
        </script>
        <?php
    } else {
        echo '<div class="notice notice-error"><p>Field export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

function galeon_handle_test_used_export($post_id)
{
    echo '<div class="notice notice-info"><p>Starting Used Yacht export for Post ID: ' . esc_html($post_id) . '...</p></div>';

    $exporter = new Galeon_Export_Handler();
    $result = $exporter->export_single_used_yacht($post_id);

    if ($result['success']) {
        $json_output = json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?>
        <div class="notice notice-success">
            <p>Used Yacht export successful!</p>
        </div>

        <div style="margin-top: 20px;">
            <button type="button" id="copy-used-json-btn" class="button button-primary" style="margin-bottom: 10px;">
                📋 Copy JSON to Clipboard
            </button>
            <span id="copy-used-status" style="margin-left: 10px; color: green; display: none;">✓ Copied!</span>
        </div>

        <pre id="used-json-output"
            style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"><?php echo esc_html($json_output); ?></pre>

        <script>
            document.getElementById('copy-used-json-btn').addEventListener('click', function () {
                const jsonText = document.getElementById('used-json-output').textContent;

                navigator.clipboard.writeText(jsonText).then(function () {
                    const status = document.getElementById('copy-used-status');
                    status.style.display = 'inline';

                    setTimeout(function () {
                        status.style.display = 'none';
                    }, 2000);
                }).catch(function (err) {
                    alert('Failed to copy: ' + err);
                });
            });
        </script>
        <?php
    } else {
        echo '<div class="notice notice-error"><p>Used Yacht export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

function galeon_handle_bulk_used_export()
{
    echo '<div class="notice notice-info"><p>Starting bulk export of all Used Yachts...</p></div>';

    $exporter = new Galeon_Export_Handler();
    $result = $exporter->export_all_used_yachts();

    if ($result['success']) {
        $json_output = json_encode($result['yachts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?>
        <div class="notice notice-success">
            <p>Bulk export successful!</p>
            <ul>
                <li>Total Used Yachts found: <?php echo esc_html($result['total']); ?></li>
                <li>Successfully exported: <?php echo esc_html($result['exported']); ?></li>
                <li>Failed: <?php echo esc_html($result['failed']); ?></li>
            </ul>
        </div>

        <?php if (!empty($result['errors'])): ?>
            <div class="notice notice-warning">
                <p><strong>Failed exports:</strong></p>
                <ul>
                    <?php foreach ($result['errors'] as $error): ?>
                        <li><?php echo esc_html($error['title']); ?> (ID: <?php echo esc_html($error['id']); ?>) -
                            <?php echo esc_html($error['error']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <button type="button" id="copy-bulk-used-json-btn" class="button button-primary" style="margin-bottom: 10px;">
                📋 Copy All Used Yachts JSON to Clipboard
            </button>
            <span id="copy-bulk-used-status" style="margin-left: 10px; color: green; display: none;">✓ Copied!</span>
        </div>

        <pre id="bulk-used-json-output"
            style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"><?php echo esc_html($json_output); ?></pre>

        <script>
            document.getElementById('copy-bulk-used-json-btn').addEventListener('click', function () {
                const jsonText = document.getElementById('bulk-used-json-output').textContent;

                navigator.clipboard.writeText(jsonText).then(function () {
                    const status = document.getElementById('copy-bulk-used-status');
                    status.style.display = 'inline';

                    setTimeout(function () {
                        status.style.display = 'none';
                    }, 2000);
                }).catch(function (err) {
                    alert('Failed to copy: ' + err);
                });
            });
        </script>
        <?php
    } else {
        echo '<div class="notice notice-error"><p>Bulk Used Yacht export failed: ' . esc_html($result['error']) . '</p></div>';
    }
}

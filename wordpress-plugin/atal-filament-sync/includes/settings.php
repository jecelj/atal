<?php
/**
 * Plugin Settings and Sync Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add main menu page
add_action('admin_menu', 'atal_sync_add_menu_page');

function atal_sync_add_menu_page()
{
    add_menu_page(
        'Sync Boats',
        'Sync Boats',
        'manage_options',
        'atal-sync',
        'atal_sync_page',
        'dashicons-update',
        30
    );
}

// Register settings
add_action('admin_init', 'atal_sync_register_settings');

function atal_sync_register_settings()
{
    register_setting('atal_sync_settings', 'atal_sync_api_url');
    register_setting('atal_sync_settings', 'atal_sync_api_key');
}

// Main sync page
function atal_sync_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle sync triggers
    $sync_result = null;
    if (isset($_POST['atal_sync_yachts']) && check_admin_referer('atal_sync_manual')) {
        $sync_result = atal_import_yachts();
    } elseif (isset($_POST['atal_sync_brands']) && check_admin_referer('atal_sync_manual')) {
        $sync_result = atal_import_brands();
    } elseif (isset($_POST['atal_sync_models']) && check_admin_referer('atal_sync_manual')) {
        $sync_result = atal_import_models();
    } elseif (isset($_POST['atal_sync_fields']) && check_admin_referer('atal_sync_manual')) {
        $sync_result = atal_import_fields();
    } elseif (isset($_POST['atal_sync_all']) && check_admin_referer('atal_sync_manual')) {
        $fields = atal_import_fields();
        $brands = atal_import_brands();
        $models = atal_import_models();
        $yachts = atal_import_yachts();
        $sync_result = [
            'imported' => ($fields['imported'] ?? 0) + ($brands['imported'] ?? 0) + ($models['imported'] ?? 0) + ($yachts['imported'] ?? 0),
            'message' => 'Synced fields, brands, models, and yachts'
        ];
    }

    if ($sync_result) {
        if (isset($sync_result['error'])) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($sync_result['error']) . '</p></div>';
        } else {
            $message = $sync_result['message'] ?? 'Successfully imported ' . esc_html($sync_result['imported']) . ' items!';
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>Sync Boats from Filament</h1>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('atal_sync_settings');
                do_settings_sections('atal_sync_settings');
                ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="atal_sync_api_url">Filament API URL</label>
                        </th>
                        <td>
                            <input type="url" id="atal_sync_api_url" name="atal_sync_api_url"
                                value="<?php echo esc_attr(get_option('atal_sync_api_url')); ?>" class="regular-text"
                                placeholder="https://yachts.atal.at/api/sync">
                            <p class="description">Base URL for Filament API</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="atal_sync_api_key">API Key</label>
                        </th>
                        <td>
                            <input type="password" id="atal_sync_api_key" name="atal_sync_api_key"
                                value="<?php echo esc_attr(get_option('atal_sync_api_key')); ?>" class="regular-text">
                            <p class="description">API key from Filament Configuration → API Settings</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Manual Sync</h2>
            <p>Sync data from Filament Admin to WordPress</p>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('atal_sync_manual'); ?>

                <p>
                    <button type="submit" name="atal_sync_all" class="button button-primary button-hero">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                        Sync Everything
                    </button>
                </p>

                <hr style="margin: 30px 0;">

                <h3>Or sync individually:</h3>

                <p>
                    <button type="submit" name="atal_sync_fields" class="button">
                        Sync Fields
                    </button>
                    <button type="submit" name="atal_sync_brands" class="button">
                        Sync Brands
                    </button>
                    <button type="submit" name="atal_sync_models" class="button">
                        Sync Models
                    </button>
                    <button type="submit" name="atal_sync_yachts" class="button">
                        Sync Yachts
                    </button>
                </p>
            </form>
        </div>
    </div>
    <?php
}

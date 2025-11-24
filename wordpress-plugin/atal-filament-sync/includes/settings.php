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
    register_setting('atal_sync_settings', 'atal_sync_allowed_languages');
    register_setting('atal_sync_settings', 'atal_sync_allowed_brands');
    register_setting('atal_sync_settings', 'atal_sync_allowed_models');
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
    } elseif (isset($_POST['atal_sync_refresh']) && check_admin_referer('atal_sync_refresh_data')) {
        $sync_result = atal_refresh_available_data();
    }

    if ($sync_result) {
        if (isset($sync_result['error'])) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($sync_result['error']) . '</p></div>';
        } else {
            $message = $sync_result['message'] ?? 'Successfully imported ' . esc_html($sync_result['imported']) . ' items!';
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    // Get available data for checkboxes
    $available_data = get_option('atal_sync_available_data', []);
    $brands = $available_data['brands'] ?? [];
    $models = $available_data['models'] ?? [];

    // Group models by brand
    $models_by_brand = [];
    foreach ($models as $model) {
        $models_by_brand[$model['brand_id']][] = $model;
    }

    $allowed_brands = get_option('atal_sync_allowed_brands', []);
    if (!is_array($allowed_brands))
        $allowed_brands = [];

    $allowed_models = get_option('atal_sync_allowed_models', []);
    if (!is_array($allowed_models))
        $allowed_models = [];

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
                    <tr>
                        <th scope="row">
                            <label for="atal_sync_allowed_languages">Allowed Languages</label>
                        </th>
                        <td>
                            <input type="text" id="atal_sync_allowed_languages" name="atal_sync_allowed_languages"
                                value="<?php echo esc_attr(get_option('atal_sync_allowed_languages')); ?>"
                                class="regular-text" placeholder="en,sl,de">
                            <p class="description">Comma-separated list of language codes to sync (e.g., "en,sl"). Leave
                                empty to auto-detect from Polylang.</p>
                        </td>
                    </tr>
                </table>

                <hr>

                <h3>Content Filtering</h3>
                <p class="description">Select which Brands and Models to import. If nothing is selected, everything will be
                    imported.</p>

                <?php if (empty($brands)): ?>
                    <div class="notice notice-warning inline">
                        <p>No brand data available. Please click "Refresh Data" below to fetch brands and models from the API.
                        </p>
                    </div>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                        <?php foreach ($brands as $brand): ?>
                            <div style="margin-bottom: 10px;">
                                <label style="font-weight: bold;">
                                    <input type="checkbox" name="atal_sync_allowed_brands[]"
                                        value="<?php echo esc_attr($brand['id']); ?>" <?php checked(in_array($brand['id'], $allowed_brands)); ?>>
                                    <?php echo esc_html($brand['translations']['en']['name'] ?? 'Unknown Brand'); ?>
                                </label>

                                <?php if (isset($models_by_brand[$brand['id']])): ?>
                                    <div style="margin-left: 20px; margin-top: 5px;">
                                        <?php foreach ($models_by_brand[$brand['id']] as $model): ?>
                                            <label style="display: block; margin-bottom: 3px;">
                                                <input type="checkbox" name="atal_sync_allowed_models[]"
                                                    value="<?php echo esc_attr($model['id']); ?>" <?php checked(in_array($model['id'], $allowed_models)); ?>>
                                                <?php echo esc_html($model['translations']['en']['name'] ?? 'Unknown Model'); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php submit_button('Save Settings'); ?>
            </form>

            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field('atal_sync_refresh_data'); ?>
                <button type="submit" name="atal_sync_refresh" class="button">
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    Refresh Data (Brands & Models)
                </button>
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

function atal_refresh_available_data()
{
    $api_url = get_option('atal_sync_api_url');
    $api_key = get_option('atal_sync_api_key');

    if (empty($api_url) || empty($api_key)) {
        return ['error' => 'API URL or Key missing'];
    }

    // Fetch Brands
    $response_brands = wp_remote_get($api_url . '/brands', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'timeout' => 30,
    ]);

    if (is_wp_error($response_brands)) {
        return ['error' => 'Failed to fetch brands: ' . $response_brands->get_error_message()];
    }

    $brands_data = json_decode(wp_remote_retrieve_body($response_brands), true);

    // Fetch Models
    $response_models = wp_remote_get($api_url . '/models', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'timeout' => 30,
    ]);

    if (is_wp_error($response_models)) {
        return ['error' => 'Failed to fetch models: ' . $response_models->get_error_message()];
    }

    $models_data = json_decode(wp_remote_retrieve_body($response_models), true);

    if (!isset($brands_data['brands']) || !isset($models_data['models'])) {
        return ['error' => 'Invalid API response format'];
    }

    update_option('atal_sync_available_data', [
        'brands' => $brands_data['brands'],
        'models' => $models_data['models'],
        'last_updated' => time(),
    ]);

    return [
        'success' => true,
        'message' => 'Successfully refreshed brands (' . count($brands_data['brands']) . ') and models (' . count($models_data['models']) . ').',
    ];
}

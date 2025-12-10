<?php
/**
 * Plugin Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add main menu page
add_action('admin_menu', 'atal_sync_add_menu_page');

function atal_sync_add_menu_page()
{
    add_menu_page(
        'Atal Sync',
        'Atal Sync',
        'manage_options',
        'atal-sync',
        'atal_sync_settings_page',
        'dashicons-cloud',
        90
    );
}

// Register settings
add_action('admin_init', 'atal_sync_register_settings');

function atal_sync_register_settings()
{
    register_setting('atal_sync_settings', 'atal_sync_api_key');
}

// Settings page content
function atal_sync_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Atal Sync Settings</h1>
        <p>This site receives data from the Master Filament Instance.</p>

        <form method="post" action="options.php">
            <?php
            settings_fields('atal_sync_settings');
            do_settings_sections('atal_sync_settings');
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="atal_sync_api_key">Sync API Key</label>
                    </th>
                    <td>
                        <input type="password" id="atal_sync_api_key" name="atal_sync_api_key"
                            value="<?php echo esc_attr(get_option('atal_sync_api_key')); ?>" class="regular-text">
                        <p class="description">Enter the API Key matching the Master Configuration.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <h3>Sync Status</h3>
        <p>
            Field Configuration:
            <?php
            $config = get_option('atal_sync_acf_config');
            if (!empty($config)) {
                echo '<span style="color: green;">Received</span>';
                echo ' (' . count($config) . ' Field Groups registered)';
            } else {
                echo '<span style="color: red;">Not Received</span>';
            }
            ?>
        </p>

    </div>
    <?php
}

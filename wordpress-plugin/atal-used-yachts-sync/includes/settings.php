<?php
/**
 * Plugin Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_options_page(
        'Atal Used Yachts Sync Settings',
        'Used Yachts Sync',
        'manage_options',
        'atal-used-yachts-sync',
        'atal_used_yachts_settings_page'
    );
});

function atal_used_yachts_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings
    if (isset($_POST['atal_used_yachts_save'])) {
        check_admin_referer('atal_used_yachts_settings');

        update_option('atal_used_yachts_api_key', sanitize_text_field($_POST['api_key']));
        update_option('atal_used_yachts_default_language', sanitize_text_field($_POST['default_language']));

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    $api_key = get_option('atal_used_yachts_api_key', '');
    $default_language = get_option('atal_used_yachts_default_language', 'en');
    $site_url = get_site_url();
    ?>
    <div class="wrap">
        <h1>Atal Used Yachts Sync Settings</h1>

        <form method="post">
            <?php wp_nonce_field('atal_used_yachts_settings'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text" id="api_key" name="api_key" value="<?php echo esc_attr($api_key); ?>"
                            class="regular-text" required>
                        <p class="description">
                            Enter the API key from your Master system. This is used to authenticate sync requests.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="default_language">Default Language</label>
                    </th>
                    <td>
                        <input type="text" id="default_language" name="default_language"
                            value="<?php echo esc_attr($default_language); ?>" class="regular-text" placeholder="en">
                        <p class="description">
                            Language code for default content (e.g., 'en', 'de', 'sl').
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Sync Endpoints</h2>
            <p>Configure these endpoints in your Master system:</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Sync Endpoint</th>
                    <td>
                        <code><?php echo esc_html($site_url); ?>/wp-json/atal-used-yachts/v1/sync</code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Config Endpoint</th>
                    <td>
                        <code><?php echo esc_html($site_url); ?>/wp-json/atal-used-yachts/v1/config</code>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="atal_used_yachts_save" class="button button-primary">
                    Save Settings
                </button>
            </p>
        </form>
    </div>
    <?php
}

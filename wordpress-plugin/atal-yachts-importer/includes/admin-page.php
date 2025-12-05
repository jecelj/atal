<?php
add_action('admin_menu', function () {
    add_menu_page('Boat Import', 'Boat Import', 'manage_options', 'atal-import', 'atal_import_page', 'dashicons-download');
});

function atal_import_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap">
        <h1>Boat Import</h1>
        
        <?php if (!function_exists('pll_set_post_language')): ?>
            <div class="notice notice-warning">
                <p><strong>Opozorilo:</strong> Polylang plugin ni aktiviran. Jeziki postov ne bodo nastavljeni.</p>
            </div>
        <?php else: ?>
            <?php
            $available_langs = [];
            if (function_exists('pll_languages_list')) {
                $available_langs = pll_languages_list(['fields' => 'slug']);
            }
            if (!empty($available_langs)):
            ?>
                <div class="notice notice-info">
                    <p><strong>Razpoložljivi jeziki v Polylang:</strong> <?php echo esc_html(implode(', ', $available_langs)); ?></p>
                    <p>Prepričajte se, da so jeziki v nastavitvah uvoza enaki kot v Polylang.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <form method="post">
            <?php wp_nonce_field('atal_import_save', 'atal_import_nonce'); ?>
            <p><label>Base API URL:</label><br>
            <input type="url" name="atal_import_url" style="width:100%" value="<?php echo esc_url(get_option('atal_import_url')); ?>" required></p>

            <p><label>Filter po brandu (za rabljene jahte):</label><br>
            <input type="text" name="atal_import_filter" value="<?php echo esc_attr(get_option('atal_import_filter')); ?>"></p>

            <p><label>Jeziki (ločeni z vejico):</label><br>
            <input type="text" name="atal_import_langs" value="<?php echo esc_attr(implode(',', (array)get_option('atal_import_langs', ['sl']))); ?>" required></p>

            <p><label>
                <input type="checkbox" name="atal_single_post_mode" value="1" <?php checked(get_option('atal_single_post_mode', false)); ?>>
                En post za vse jezike (večjezična ACF polja v enem postu)
            </label><br>
            <span class="description">Če je omogočeno, se ustvari samo en post z vsemi jezikovnimi polji. Če je onemogočeno, se ustvari ločen post za vsak jezik.</span></p>

            <p><input type="submit" name="save_import_settings" class="button button-primary" value="Shrani nastavitve"></p>
        </form>

        <form method="post">
            <?php wp_nonce_field('atal_import_run', 'atal_import_nonce'); ?>
            <input type="submit" name="atal_import_now" class="button button-secondary" value="Import Yachts Now">
        </form>
        
        <?php if (function_exists('atal_sync_acf_field_groups')): ?>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>">
            <input type="hidden" name="page" value="atal-import">
            <input type="hidden" name="atal_sync_acf" value="1">
            <?php wp_nonce_field('atal_sync_acf'); ?>
            <p>
                <input type="submit" class="button button-secondary" value="Sinhroniziraj ACF Field Groups">
                <span class="description">To bo avtomatsko ustvarilo ACF field groups iz glavne strani</span>
            </p>
        </form>
        <?php endif; ?>
        
        <form method="post">
            <?php wp_nonce_field('atal_sync_terms', 'atal_sync_terms_nonce'); ?>
            <p>
                <input type="submit" name="atal_sync_terms_now" class="button button-secondary" value="Sinhroniziraj Kategorije">
                <span class="description">To bo sinhroniziralo kategorije iz glavne strani</span>
            </p>
        </form>
        
        <?php if (isset($_GET['atal_acf_synced']) && $_GET['atal_acf_synced'] == '1'): ?>
            <div class="notice notice-success"><p>ACF field groups so bili uspešno sinhronizirani!</p></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['atal_acf_sync_error'])): ?>
            <?php 
            $error_code = $_GET['atal_acf_sync_error'];
            $error_messages = [
                '1' => 'Napaka pri sinhronizaciji ACF field groups. Preveri error log za več podrobnosti.',
                '2' => 'ACF plugin ni nameščen ali aktiviran. Prosimo, namestite Advanced Custom Fields plugin.',
                '3' => 'Kritična napaka pri sinhronizaciji. Preveri error log za več podrobnosti.',
            ];
            $message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : 'Neznana napaka.';
            ?>
            <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <h3>Log:</h3>
        <pre style="background:#f6f6f6;padding:10px;"><?php echo esc_html(get_option('atal_import_log', '')); ?></pre>
    </div>
    <?php
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['save_import_settings']) && check_admin_referer('atal_import_save', 'atal_import_nonce')) {
        update_option('atal_import_url', esc_url_raw($_POST['atal_import_url']));
        update_option('atal_import_filter', sanitize_text_field($_POST['atal_import_filter']));
        $langs = array_map('trim', explode(',', sanitize_text_field($_POST['atal_import_langs'])));
        $langs = array_filter(array_map('sanitize_text_field', $langs));
        update_option('atal_import_langs', $langs);
        update_option('atal_single_post_mode', isset($_POST['atal_single_post_mode']) && $_POST['atal_single_post_mode'] == '1');
    }

    if (isset($_POST['atal_import_now']) && check_admin_referer('atal_import_run', 'atal_import_nonce')) {
        atal_import_process();
    }
    
    if (isset($_POST['atal_sync_terms_now']) && check_admin_referer('atal_sync_terms', 'atal_sync_terms_nonce')) {
        atal_sync_taxonomy_terms();
    }
});

/**
 * Sinhronizira taxonomy terms iz glavne strani (manual admin trigger)
 */
function atal_sync_taxonomy_terms() {
    $base_url = get_option('atal_import_url');
    if (empty($base_url)) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Napaka: Import URL ni nastavljen!</p></div>';
        });
        return;
    }
    
    // Uporabi silent funkcijo iz importer-functions.php
    $synced_count = atal_sync_taxonomy_terms_silent($base_url);
    
    if ($synced_count === false) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Napaka pri sinhronizaciji kategorij! Preveri debug log za več podrobnosti.</p></div>';
        });
    } else {
        add_action('admin_notices', function() use ($synced_count) {
            echo '<div class="notice notice-success"><p>Uspešno sinhronizirano ' . $synced_count . ' kategorij!</p></div>';
        });
    }
}


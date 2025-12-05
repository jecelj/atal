<?php
// ===============================
//  Atal Importer Functions (v2)
// ===============================

if (!defined('ABSPATH'))
    exit;

/**
 * Sinhronizira taxonomy terms (silent mode - za uporabo med importom)
 * Returns: število sinhroniziranih termov ali false v primeru napake
 */
function atal_sync_taxonomy_terms_silent($base_url)
{
    // Odstrani /export iz konca URL-ja če obstaja
    $base_url = preg_replace('#/wp-json/atal-sync/v1/export$#', '', $base_url);

    // Gradi API URL za taxonomy terms
    $api_url = $base_url . '/wp-json/atal-sync/v1/export-terms?key=' . ATAL_IMPORT_API_KEY;

    error_log('Atal Taxonomy Sync: Fetching terms from: ' . $api_url);

    $response = wp_remote_get($api_url, ['timeout' => 30]);

    if (is_wp_error($response)) {
        error_log('Atal Taxonomy Sync: ERROR - ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data) || !isset($data['terms'])) {
        error_log('Atal Taxonomy Sync: ERROR - Invalid response format');
        return false;
    }

    $synced_count = 0;
    foreach ($data['terms'] as $taxonomy => $terms) {
        if (!taxonomy_exists($taxonomy)) {
            error_log('Atal Taxonomy Sync: Taxonomy ' . $taxonomy . ' does not exist, skipping');
            continue;
        }

        error_log('Atal Taxonomy Sync: Syncing ' . count($terms) . ' terms for ' . $taxonomy);

        // POMEMBNO: Najprej kreiraj parent terms, nato child terms
        // To prepreči napake ko poskušamo nastaviti parent ki še ne obstaja

        // Faza 1: Kreiraj vse parent terms (parent = 0)
        foreach ($terms as $term_data) {
            if (!empty($term_data['parent'])) {
                continue; // Skip child terms v fazi 1
            }

            $existing_term = term_exists($term_data['slug'], $taxonomy);

            $term_args = [
                'description' => $term_data['description'],
                'slug' => $term_data['slug'],
            ];

            if ($existing_term) {
                wp_update_term($existing_term['term_id'], $taxonomy, array_merge($term_args, [
                    'name' => $term_data['name'],
                ]));
                error_log('Atal Taxonomy Sync: Updated parent term: ' . $term_data['name']);
            } else {
                wp_insert_term($term_data['name'], $taxonomy, $term_args);
                error_log('Atal Taxonomy Sync: Created parent term: ' . $term_data['name']);
            }

            $synced_count++;
        }

        // Faza 2: Kreiraj child terms in nastavi parent
        foreach ($terms as $term_data) {
            if (empty($term_data['parent'])) {
                continue; // Skip parent terms v fazi 2
            }

            $existing_term = term_exists($term_data['slug'], $taxonomy);

            $term_args = [
                'description' => $term_data['description'],
                'slug' => $term_data['slug'],
            ];

            // Poskusi najti parent term po source_id (term_id iz strani1)
            // Potrebujemo mapping med source term_id in local term_id
            // Za zdaj: poskusi najti parent po slug iz vseh terms
            $parent_slug = null;
            foreach ($terms as $potential_parent) {
                if ($potential_parent['term_id'] == $term_data['parent']) {
                    $parent_slug = $potential_parent['slug'];
                    break;
                }
            }

            if ($parent_slug) {
                $parent_term = get_term_by('slug', $parent_slug, $taxonomy);
                if ($parent_term) {
                    $term_args['parent'] = $parent_term->term_id;
                    error_log('Atal Taxonomy Sync: Setting parent for ' . $term_data['name'] . ' to ' . $parent_term->name . ' (ID: ' . $parent_term->term_id . ')');
                }
            }

            if ($existing_term) {
                wp_update_term($existing_term['term_id'], $taxonomy, array_merge($term_args, [
                    'name' => $term_data['name'],
                ]));
                error_log('Atal Taxonomy Sync: Updated child term: ' . $term_data['name']);
            } else {
                wp_insert_term($term_data['name'], $taxonomy, $term_args);
                error_log('Atal Taxonomy Sync: Created child term: ' . $term_data['name']);
            }

            $synced_count++;
        }
    }

    error_log('Atal Taxonomy Sync: Successfully synced ' . $synced_count . ' terms');
    return $synced_count;
}

/**
 * Registracija REST endpointa za ročni uvoz
 * primer: https://atal.sk/wp-json/atal-import/v1/run?key=API_KEY
 */
add_action('rest_api_init', function () {
    register_rest_route('atal-import/v1', '/run', [
        'methods' => 'GET',
        'callback' => 'atal_import_run',
        'permission_callback' => '__return_true', // Dodaj permission callback
    ]);

    // Debug endpoint za preverjanje podatkov iz API-ja
    register_rest_route('atal-import/v1', '/debug-api', [
        'methods' => 'GET',
        'callback' => 'atal_import_debug_api',
        'permission_callback' => '__return_true',
    ]);

});

function atal_import_run($request)
{
    $key = $request->get_param('key');
    if ($key !== ATAL_IMPORT_API_KEY) {
        return new WP_Error('forbidden', 'Neveljaven API ključ', ['status' => 403]);
    }
    return atal_import_process();
}

/**
 * Debug endpoint za preverjanje podatkov iz API-ja
 * Primer: /wp-json/atal-import/v1/debug-api?lang=en
 */
function atal_import_debug_api($request)
{
    $url = get_option('atal_import_url');
    $lang = $request->get_param('lang') ?: 'en';

    if (empty($url)) {
        return ['error' => 'Missing import URL'];
    }

    $api = add_query_arg('lang', $lang, $url);
    if (defined('ATAL_IMPORT_API_KEY')) {
        $api = add_query_arg('key', ATAL_IMPORT_API_KEY, $api);
    }
    $response = wp_remote_get($api, ['timeout' => 30]);

    if (is_wp_error($response)) {
        return [
            'error' => $response->get_error_message(),
            'url' => $api
        ];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Pripravi podrobne podatke o vsakem itemu
    $detailed_items = [];
    if (is_array($data)) {
        foreach ($data as $idx => $item) {
            $detailed_items[] = [
                'index' => $idx,
                'id' => $item['id'] ?? 'NOT SET',
                'type' => $item['type'] ?? 'NOT SET',
                'title_raw' => isset($item['title']) ? (is_array($item['title']) ? $item['title'] : $item['title']) : 'NOT SET',
                'title_rendered' => isset($item['title']['rendered']) ? $item['title']['rendered'] : (isset($item['title']) && is_string($item['title']) ? $item['title'] : 'NOT SET'),
                'title_type' => gettype($item['title'] ?? null),
                'acf_keys' => isset($item['acf']) && is_array($item['acf']) ? array_keys($item['acf']) : [],
                'acf_brand' => isset($item['acf']['brand']) ? $item['acf']['brand'] : 'NOT SET',
                'acf_text' => isset($item['acf']['text']) ? substr($item['acf']['text'], 0, 100) : 'NOT SET',
                'full_item' => $item, // Celoten item za pregled
            ];
        }
    }

    return [
        'url' => $api,
        'response_code' => wp_remote_retrieve_response_code($response),
        'items_count' => is_array($data) ? count($data) : 0,
        'raw_response_length' => strlen($body),
        'raw_response_preview' => substr($body, 0, 5000), // Prvih 5000 znakov
        'parsed_data' => is_array($data) ? array_slice($data, 0, 5) : null, // Prvih 5 itemov
        'detailed_items' => $detailed_items,
        'first_item_structure' => is_array($data) && isset($data[0]) ? [
            'keys' => array_keys($data[0]),
            'id' => $data[0]['id'] ?? 'NOT SET',
            'type' => $data[0]['type'] ?? 'NOT SET',
            'title' => $data[0]['title'] ?? 'NOT SET',
            'title_type' => gettype($data[0]['title'] ?? null),
            'acf' => $data[0]['acf'] ?? 'NOT SET',
        ] : null,
    ];
}


/**
 * Uvozi ACF Gallery polje (array slik)
 * 
 * @param array $gallery_data Array slik iz API-ja (lahko je array ID-jev ali array objektov)
 * @param int $post_id ID posta kamor se shranjuje
 * @param string $field_name Ime polja
 * @param string $lang Jezik
 * @return array Array attachment ID-jev
 */
function atal_import_gallery_field($gallery_data, $post_id, $field_name, $lang)
{
    if (!is_array($gallery_data) || empty($gallery_data)) {
        return [];
    }

    $attachment_ids = [];

    foreach ($gallery_data as $index => $image_data) {
        $attachment_id = null;
        $image_url = null;

        // Preveri format podatkov
        if (is_numeric($image_data)) {
            // Direktno attachment ID
            $attachment_id = intval($image_data);
        } elseif (is_array($image_data)) {
            // Array z ID in URL
            if (isset($image_data['ID']) && is_numeric($image_data['ID'])) {
                $attachment_id = intval($image_data['ID']);
            } elseif (isset($image_data['id']) && is_numeric($image_data['id'])) {
                $attachment_id = intval($image_data['id']);
            }

            if (isset($image_data['url']) && !empty($image_data['url'])) {
                $image_url = $image_data['url'];
            }
        }

        // Preveri, ali attachment že obstaja na strani 2
        $local_attachment_id = null;
        if ($attachment_id) {
            $attachment = get_post($attachment_id);
            if ($attachment && $attachment->post_type === 'attachment') {
                $local_attachment_id = $attachment_id;
            }
        }

        // Če attachment ne obstaja in imamo URL, ga uvozi
        if (!$local_attachment_id && $image_url) {
            // Preveri, ali slika že obstaja (po URL-ju)
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            $existing_attachment = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'any',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_atal_source_image_url',
                        'value' => $image_url,
                        'compare' => '='
                    ]
                ],
                'fields' => 'ids'
            ]);

            if (!empty($existing_attachment)) {
                $local_attachment_id = $existing_attachment[0];
                error_log("Atal Import Gallery: Found existing attachment ID {$local_attachment_id} for URL '{$image_url}'");
            } else {
                // Uvozi sliko
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $tmp = download_url($image_url);

                if (!is_wp_error($tmp)) {
                    $file_array = [
                        'name' => $filename,
                        'tmp_name' => $tmp
                    ];

                    $local_attachment_id = media_handle_sideload($file_array, $post_id);

                    if (!is_wp_error($local_attachment_id)) {
                        // Shrani source URL za prihodnje preverjanje
                        update_post_meta($local_attachment_id, '_atal_source_image_url', $image_url);
                        error_log("Atal Import Gallery: Successfully imported image from URL '{$image_url}' as attachment ID {$local_attachment_id}");
                    } else {
                        error_log("Atal Import Gallery: Failed to import image from URL '{$image_url}': " . $local_attachment_id->get_error_message());
                        $local_attachment_id = null;
                    }
                } else {
                    error_log("Atal Import Gallery: Failed to download image from URL '{$image_url}': " . $tmp->get_error_message());
                }
            }
        }

        // Dodaj attachment ID v array
        if ($local_attachment_id) {
            $attachment_ids[] = $local_attachment_id;
        }
    }

    return $attachment_ids;
}

/**
 * Poišče obstoječi post glede na izvorni ID in jezik (za Polylang)
 * Polylang ustvarja ločene poste za vsak jezik
 */
function atal_get_existing_post_id($source_id, $post_type, $lang)
{
    // Najprej poišči vse poste z istim source_id
    $q = new WP_Query([
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'meta_key' => '_atal_source_id',
        'meta_value' => (string) $source_id,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);

    if (!$q->have_posts()) {
        return 0;
    }

    // Preveri jezik vsakega posta preko Polylang funkcije
    foreach ($q->posts as $post_id) {
        $post_lang = '';
        if (function_exists('pll_get_post_language')) {
            $post_lang = pll_get_post_language($post_id);
        }

        // Fallback: preveri meta polje (če Polylang ni aktiviran)
        if (empty($post_lang)) {
            $post_lang = get_post_meta($post_id, 'pll_language', true);
        }

        // Preveri tudi _atal_source_lang meta polje
        if (empty($post_lang)) {
            $post_lang = get_post_meta($post_id, '_atal_source_lang', true);
        }

        if ($post_lang === $lang) {
            return (int) $post_id;
        }
    }

    return 0;
}

/**
 * Poišče vse poste z istim source_id (za vse jezike) - za Polylang povezovanje
 */
function atal_get_all_posts_by_source_id($source_id, $post_type)
{
    $q = new WP_Query([
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'meta_key' => '_atal_source_id',
        'meta_value' => (string) $source_id,
        'fields' => 'ids',
    ]);
    return $q->posts ?? [];
}

/**
 * Poveže post z Polylang sistemom
 * Polylang povezuje poste preko meta polja pll_language in pll_translations
 */
function atal_link_polylang_post($post_id, $lang, $source_id = 0)
{
    // Preveri, ali je Polylang aktiviran
    if (!function_exists('pll_set_post_language')) {
        error_log("Atal Import: Polylang ni aktiviran - jezik ne bo nastavljen");
        return false;
    }

    // Preveri, ali je jezik veljaven v Polylang
    $available_langs = [];
    if (function_exists('pll_languages_list')) {
        $available_langs = pll_languages_list(['fields' => 'slug']);
    } elseif (function_exists('pll_the_languages')) {
        // Alternativa: pridobi jezike iz Polylang objekta
        global $polylang;
        if (isset($polylang) && method_exists($polylang, 'model')) {
            $languages = $polylang->model->get_languages_list();
            foreach ($languages as $language) {
                $available_langs[] = $language->slug;
            }
        }
    }

    if (!empty($available_langs) && !in_array($lang, $available_langs)) {
        error_log("Atal Import: Jezik '{$lang}' ni veljaven v Polylang. Razpoložljivi jeziki: " . implode(', ', $available_langs));
        // Poskusi z osnovnim jezikom (npr. 'en' namesto 'en_US')
        $lang_parts = explode('_', $lang);
        $base_lang = $lang_parts[0];
        if (in_array($base_lang, $available_langs)) {
            $lang = $base_lang;
            error_log("Atal Import: Uporabljam osnovni jezik '{$lang}'");
        } else {
            error_log("Atal Import: Ne morem nastaviti jezika - uporabim prvi razpoložljivi jezik");
            $lang = $available_langs[0] ?? 'en';
        }
    }

    // Nastavi jezik posta preko Polylang funkcije (to je ključno!)
    $result = pll_set_post_language($post_id, $lang);
    if (!$result) {
        error_log("Atal Import: Napaka pri nastavitvi jezika za post {$post_id} na '{$lang}'");
        return false;
    }

    error_log("Atal Import: Jezik posta {$post_id} nastavljen na '{$lang}'");

    // Poišči vse poste z istim source_id (različni jeziki)
    if ($source_id) {
        $translations = [];
        $all_posts = atal_get_all_posts_by_source_id($source_id, get_post_type($post_id));

        foreach ($all_posts as $linked_post_id) {
            if ($linked_post_id != $post_id) {
                $linked_lang = pll_get_post_language($linked_post_id);
                if (!empty($linked_lang)) {
                    $translations[$linked_lang] = $linked_post_id;
                }
            }
        }

        // Dodaj trenutni post v translations
        $translations[$lang] = $post_id;

        // Pomembno: Shrani translations za vse poste preko Polylang funkcije
        // To povezuje poste kot prevode, kar omogoča Polylang filtriranje
        if (function_exists('pll_save_post_translations')) {
            $save_result = pll_save_post_translations($translations);
            if ($save_result) {
                error_log("Atal Import: Translations shranjene za post {$post_id}: " . print_r($translations, true));

                // Preveri, ali so translations res shranjene
                $verify_translations = pll_get_post_translations($post_id);
                error_log("Atal Import: Verified translations for post {$post_id}: " . print_r($verify_translations, true));
            } else {
                error_log("Atal Import: WARNING - Failed to save translations for post {$post_id}");
            }
        } else {
            // Fallback: shrani direktno v meta polje (manj zanesljivo)
            foreach ($translations as $trans_lang => $trans_post_id) {
                update_post_meta($trans_post_id, 'pll_translations', $translations);
            }
            error_log("Atal Import: Translations shranjene direktno v meta polje (Polylang funkcija ni na voljo)");
        }
    }

    return true;
}

/**
 * Izlušči vrednost iz ACF polja z jezikovnim sufiksom
 * Primer: title_en, title_sl, text_en, text_sl
 */
function atal_get_acf_value_by_lang($acf_data, $field_name, $lang)
{
    if (!is_array($acf_data)) {
        error_log("Atal Import: atal_get_acf_value_by_lang - acf_data is not an array!");
        return '';
    }

    // Poskusi direktno z jezikovnim sufiksom
    $lang_suffix = '_' . $lang;
    $field_key = $field_name . $lang_suffix;

    if (isset($acf_data[$field_key])) {
        $value = $acf_data[$field_key];
        // Preveri, ali je vrednost veljavna (ne sme biti samo ime polja)
        if (!empty($value) && $value !== $field_key && $value !== $field_name) {
            error_log("Atal Import: Found value for '{$field_key}': " . (is_string($value) ? substr($value, 0, 100) : gettype($value)));
            return is_string($value) ? trim($value) : $value;
        } else {
            error_log("Atal Import: Field '{$field_key}' exists but value is invalid: " . var_export($value, true));
        }
    }

    // Fallback: poskusi brez sufiksa (če je samo ena vrednost)
    if (isset($acf_data[$field_name])) {
        $value = $acf_data[$field_name];
        // Preveri, ali je vrednost veljavna
        if (!empty($value) && $value !== $field_name) {
            error_log("Atal Import: Found value for '{$field_name}' (without suffix): " . (is_string($value) ? substr($value, 0, 100) : gettype($value)));
            return is_string($value) ? trim($value) : $value;
        }
    }

    error_log("Atal Import: No valid value found for field '{$field_name}' with lang '{$lang}'");
    return '';
}

/**
 * Uvoz v single post mode - en post z vsemi jezikovnimi polji
 */
function atal_import_single_post_mode($url, $filter, $langs)
{
    @set_time_limit(600);
    @ini_set('max_execution_time', 600);
    @ini_set('memory_limit', '512M');

    $log = "";

    // Zberi podatke za vse jezike
    $all_items = [];

    foreach ($langs as $lang) {
        $api = add_query_arg('lang', $lang, $url);
        if (defined('ATAL_IMPORT_API_KEY')) {
            $api = add_query_arg('key', ATAL_IMPORT_API_KEY, $api);
        }

        error_log("Atal Import (Single Mode): Fetching data for lang '{$lang}' from: {$api}");
        $response = wp_remote_get($api, ['timeout' => 30]);

        if (is_wp_error($response)) {
            error_log("Atal Import (Single Mode): Error fetching data for lang '{$lang}': " . $response->get_error_message());
            $log .= strtoupper($lang) . ": error - " . $response->get_error_message() . "\n";
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            error_log("Atal Import (Single Mode): Invalid response for lang '{$lang}'");
            $log .= strtoupper($lang) . ": invalid or empty response\n";
            continue;
        }

        // Združi podatke po source_id
        foreach ($data as $item) {
            $source_id = isset($item['id']) ? (int) $item['id'] : 0;
            if ($source_id === 0)
                continue;

            if (!isset($all_items[$source_id])) {
                $all_items[$source_id] = [
                    'source_id' => $source_id,
                    'post_type' => $item['type'] ?? 'post',
                    'acf' => [],
                ];
            }

            // Združi ACF polja za vse jezike
            $item_acf = $item['acf'] ?? [];
            foreach ($item_acf as $key => $value) {
                // Shrani polja z jezikovnimi sufiksi direktno
                $all_items[$source_id]['acf'][$key] = $value;
            }
        }
    }

    error_log("Atal Import (Single Mode): Collected " . count($all_items) . " unique items");

    // Ustvari ali posodobi poste
    foreach ($all_items as $source_id => $item_data) {
        $post_type = $item_data['post_type'];
        $acf = $item_data['acf'];

        // Filtriranje po brandu
        if ($filter && isset($acf['brand']) && stripos($acf['brand'], $filter) === false) {
            continue;
        }

        // Pridobi naslov za prvi jezik (za post title)
        $post_title = '';
        foreach ($langs as $lang) {
            $title = atal_get_acf_value_by_lang($acf, 'title', $lang);
            if (!empty($title)) {
                $post_title = $title;
                break;
            }
        }

        if (empty($post_title)) {
            error_log("Atal Import (Single Mode): Skipping item {$source_id} - no title found");
            continue;
        }

        // Poišči obstoječi post
        $existing_id = 0;
        if ($source_id) {
            $q = new WP_Query([
                'post_type' => $post_type,
                'posts_per_page' => 1,
                'meta_key' => '_atal_source_id',
                'meta_value' => (string) $source_id,
                'fields' => 'ids',
            ]);
            if ($q->have_posts()) {
                $existing_id = $q->posts[0];
            }
        }

        if ($existing_id) {
            // Posodobi obstoječi post
            wp_update_post([
                'ID' => $existing_id,
                'post_title' => $post_title,
            ]);
            $id = $existing_id;
            error_log("Atal Import (Single Mode): Updated post {$id}");
        } else {
            // Ustvari nov post
            $id = wp_insert_post([
                'post_type' => $post_type,
                'post_title' => $post_title,
                'post_status' => 'publish',
                'post_author' => 1,
            ]);

            if ($id > 0 && !is_wp_error($id)) {
                update_post_meta($id, '_atal_source_id', (string) $source_id);
                error_log("Atal Import (Single Mode): Created post {$id}");
            } else {
                continue;
            }
        }

        // Nastavi jezik posta na prvi jezik
        if (function_exists('pll_set_post_language') && !empty($langs)) {
            $first_lang = $langs[0];
            $available_langs = [];
            if (function_exists('pll_languages_list')) {
                $available_langs = pll_languages_list(['fields' => 'slug']);
            } elseif (function_exists('pll_the_languages')) {
                global $polylang;
                if (isset($polylang) && method_exists($polylang, 'model')) {
                    $languages = $polylang->model->get_languages_list();
                    foreach ($languages as $language) {
                        $available_langs[] = $language->slug;
                    }
                }
            }

            if (!empty($available_langs) && in_array($first_lang, $available_langs)) {
                pll_set_post_language($id, $first_lang);
                error_log("Atal Import (Single Mode): Set language for post {$id} to '{$first_lang}'");
            } else {
                // Poskusi z osnovnim jezikom
                $lang_parts = explode('_', $first_lang);
                $base_lang = $lang_parts[0];
                if (!empty($available_langs) && in_array($base_lang, $available_langs)) {
                    pll_set_post_language($id, $base_lang);
                    error_log("Atal Import (Single Mode): Set language for post {$id} to '{$base_lang}' (from '{$first_lang}')");
                } elseif (!empty($available_langs)) {
                    // Uporabi prvi razpoložljivi jezik
                    $fallback_lang = $available_langs[0];
                    pll_set_post_language($id, $fallback_lang);
                    error_log("Atal Import (Single Mode): Set language for post {$id} to '{$fallback_lang}' (fallback)");
                }
            }
        }

        // Shrani vsa ACF polja (z jezikovnimi sufiksi)
        foreach ($acf as $key => $val) {
            $key = sanitize_key($key);
            if (empty($key))
                continue;

            if (is_string($val)) {
                update_post_meta($id, $key, sanitize_text_field($val));
            } elseif (is_array($val)) {
                // Za image polja
                if (isset($val['url']) || isset($val['ID'])) {
                    // Shrani attachment ID če obstaja
                    $attachment_id = null;
                    if (isset($val['ID']) && is_numeric($val['ID'])) {
                        $attachment_id = intval($val['ID']);
                    } elseif (isset($val['id']) && is_numeric($val['id'])) {
                        $attachment_id = intval($val['id']);
                    }

                    if ($attachment_id) {
                        // Preveri, ali attachment obstaja
                        $attachment = get_post($attachment_id);
                        if ($attachment && $attachment->post_type === 'attachment') {
                            update_post_meta($id, $key, $attachment_id);
                        } elseif (isset($val['url'])) {
                            // Uvozi sliko če je potrebno
                            $image_url = $val['url'];
                            $filename = basename(parse_url($image_url, PHP_URL_PATH));
                            require_once(ABSPATH . 'wp-admin/includes/media.php');
                            require_once(ABSPATH . 'wp-admin/includes/file.php');
                            require_once(ABSPATH . 'wp-admin/includes/image.php');

                            $tmp = download_url($image_url, 300);
                            if (!is_wp_error($tmp)) {
                                $file_array = ['name' => $filename, 'tmp_name' => $tmp];
                                $local_attachment_id = media_handle_sideload($file_array, $id);
                                if (!is_wp_error($local_attachment_id)) {
                                    update_post_meta($local_attachment_id, '_atal_source_image_url', $image_url);
                                    update_post_meta($id, $key, $local_attachment_id);
                                }
                            }
                        }
                    }
                } else {
                    update_post_meta($id, $key, $val);
                }
            } elseif (is_numeric($val)) {
                update_post_meta($id, $key, $val);
            }
        }

        // Taksonomija
        if ($post_type === 'new_yachts' && !empty($acf['brand'])) {
            $term = term_exists($acf['brand'], 'new_yacht_category');
            if (!$term || is_wp_error($term)) {
                $term = wp_insert_term($acf['brand'], 'new_yacht_category');
            }
            if (!is_wp_error($term)) {
                wp_set_object_terms($id, intval($term['term_id']), 'new_yacht_category');
            }
        }
    }

    $log .= "Single mode: " . count($all_items) . " postov obdelanih\n";
    update_option('atal_import_log', $log);
    error_log("Atal Import (Single Mode): === Import finished ===\n$log");

    return ['status' => 'ok', 'log' => $log];
}

/**
 * Poveže dva posta preko Polylang sistema
 * Uporablja se, ko se ustvari nov post za jezik, ki že ima povezan post za drug jezik
 */
function atal_link_polylang_posts($new_post_id, $existing_post_id, $new_lang)
{
    // Nastavi jezik novega posta
    update_post_meta($new_post_id, 'pll_language', $new_lang);
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($new_post_id, $new_lang);
    }
    error_log("Atal Import: Set pll_language for post {$new_post_id} to '{$new_lang}'");

    // Pridobi jezik obstoječega posta
    $existing_lang = get_post_meta($existing_post_id, 'pll_language', true);
    if (empty($existing_lang) && function_exists('pll_get_post_language')) {
        $existing_lang = pll_get_post_language($existing_post_id);
    }
    if (empty($existing_lang)) {
        error_log("Atal Import: WARNING - Existing post {$existing_post_id} has no language set!");
        return false;
    }
    error_log("Atal Import: Existing post {$existing_post_id} lang: {$existing_lang}");

    // Pridobi obstoječe translations iz obstoječega posta
    $existing_translations = get_post_meta($existing_post_id, 'pll_translations', true);
    if (!is_array($existing_translations)) {
        $existing_translations = [];
    }
    error_log("Atal Import: Existing translations for post {$existing_post_id}: " . print_r($existing_translations, true));

    // Dodaj nov post v translations
    $existing_translations[$new_lang] = $new_post_id;

    // Nastavi translations za nov post - vključi obstoječi post in vse druge translations
    $new_translations = [];
    $new_translations[$existing_lang] = $existing_post_id;

    // Dodaj tudi vse druge translations iz obstoječega posta
    foreach ($existing_translations as $trans_lang => $trans_post_id) {
        if ($trans_lang !== $new_lang && $trans_post_id != $new_post_id) {
            $new_translations[$trans_lang] = $trans_post_id;
        }
    }
    $new_translations[$new_lang] = $new_post_id;

    // Shrani translations za vse poste
    foreach ($existing_translations as $trans_lang => $trans_post_id) {
        update_post_meta($trans_post_id, 'pll_translations', $existing_translations);
    }
    update_post_meta($new_post_id, 'pll_translations', $new_translations);

    // Uporabi Polylang funkcijo, če je na voljo
    if (function_exists('pll_save_post_translations')) {
        pll_save_post_translations($existing_translations);
    }

    error_log("Atal Import: Successfully linked post {$new_post_id} (lang: {$new_lang}) with post {$existing_post_id} (lang: {$existing_lang})");
    return true;
}

// Opomba: ACF field groups se NE registrirajo avtomatsko na podstraneh
// Podatki se shranjujejo kot post meta in so dostopni brez ACF
// Če bi v prihodnosti želeli avtomatsko registracijo, lahko dodamo to funkcijo nazaj

/**
 * Glavni proces uvoza
 */
function atal_import_process()
{
    // Povečaj execution time limit za velike uvoze
    @set_time_limit(600); // 10 minut
    @ini_set('max_execution_time', 600);

    // Povečaj memory limit za velike uvoze
    @ini_set('memory_limit', '512M');
    $url = get_option('atal_import_url');
    $filter = get_option('atal_import_filter');
    $langs = get_option('atal_import_langs', []);
    $single_post_mode = get_option('atal_single_post_mode', false);
    $log = "";

    if (empty($url)) {
        return ['status' => 'error', 'log' => 'Missing import URL'];
    }

    // Debug: izpiši nastavitve
    error_log("Atal Import: === Starting import process ===");
    error_log("Atal Import: URL: {$url}");
    error_log("Atal Import: Filter: " . ($filter ?: 'NONE'));
    error_log("Atal Import: Languages: " . (is_array($langs) ? implode(', ', $langs) : 'NOT ARRAY'));
    error_log("Atal Import: Languages count: " . (is_array($langs) ? count($langs) : 'N/A'));
    error_log("Atal Import: Single post mode: " . ($single_post_mode ? 'YES' : 'NO'));

    // Preveri, ali je $langs array
    if (!is_array($langs)) {
        $langs = array_filter(array_map('trim', explode(',', $langs)));
        error_log("Atal Import: Converted langs to array: " . implode(', ', $langs));
    }

    // Preveri, ali je $langs prazen
    if (empty($langs)) {
        error_log("Atal Import: ERROR - No languages configured!");
        return ['status' => 'error', 'log' => 'No languages configured'];
    }

    // Opomba: ACF field groups se NE registrirajo avtomatsko na podstraneh
    // Podatki se shranjujejo kot post meta in so dostopni brez ACF
    // Če je ACF Pro nameščen, se field groups lahko registrirajo (opcijsko)
    // Vendar to NI potrebno za delovanje sistema

    // Avtomatsko sinhronizira taxonomy terms pred začetkom importa
    error_log("Atal Import: Auto-syncing taxonomy terms...");
    $terms_synced = atal_sync_taxonomy_terms_silent($url);
    if ($terms_synced !== false) {
        error_log("Atal Import: Taxonomy terms synced: {$terms_synced} terms");
    } else {
        error_log("Atal Import: Taxonomy sync failed or skipped");
    }

    // Če je single_post_mode, zberi vse podatke za vse jezike in ustvari en post
    if ($single_post_mode) {
        error_log("Atal Import: Using single post mode");
        return atal_import_single_post_mode($url, $filter, $langs);
    }

    // Standardni način: ločeni posti za vsak jezik
    error_log("Atal Import: Using separate posts mode - will process " . count($langs) . " language(s)");
    foreach ($langs as $lang_index => $lang) {
        $lang = trim($lang);
        error_log("Atal Import: === Processing language #{$lang_index}: '{$lang}' ===");
        // Dodaj API ključ v URL za avtorizacijo
        $api = add_query_arg('lang', $lang, $url);
        if (defined('ATAL_IMPORT_API_KEY')) {
            $api = add_query_arg('key', ATAL_IMPORT_API_KEY, $api);
        }
        error_log("Atal Import: Fetching data for lang '{$lang}' from: {$api}");
        $response = wp_remote_get($api, ['timeout' => 30]);

        if (is_wp_error($response)) {
            error_log("Atal Import: Error fetching data for lang '{$lang}': " . $response->get_error_message());
            $log .= strtoupper($lang) . ": error - " . $response->get_error_message() . "\n";
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            error_log("Atal Import: Invalid response for lang '{$lang}'. Response: " . substr($body, 0, 500));
            $log .= strtoupper($lang) . ": invalid or empty response\n";
            continue;
        }

        error_log("Atal Import: Received " . count($data) . " items for lang '{$lang}'");

        // Debug: izpiši prvi item, da vidimo strukturo
        if (!empty($data) && isset($data[0])) {
            $first_item_debug = print_r($data[0], true);
            error_log("Atal Import: First item structure: " . $first_item_debug);

            // Dodatno: preveri strukturo podatkov
            error_log("Atal Import: First item keys: " . implode(', ', array_keys($data[0])));
            if (isset($data[0]['id'])) {
                error_log("Atal Import: First item ID: " . $data[0]['id']);
            }
            if (isset($data[0]['title'])) {
                error_log("Atal Import: First item title structure: " . print_r($data[0]['title'], true));
                if (isset($data[0]['title']['rendered'])) {
                    error_log("Atal Import: First item title rendered: " . $data[0]['title']['rendered']);
                }
            }
            if (isset($data[0]['acf'])) {
                error_log("Atal Import: First item ACF keys: " . implode(', ', array_keys($data[0]['acf'])));
                error_log("Atal Import: First item ACF content: " . print_r($data[0]['acf'], true));
            }
        } else {
            error_log("Atal Import: WARNING - Data array is empty or first item doesn't exist!");
        }

        foreach ($data as $item_index => $item) {
            // Debug: izpiši strukturo vsakega itema
            error_log("Atal Import: Processing item #{$item_index} - keys: " . implode(', ', array_keys($item)));

            $acf = $item['acf'] ?? [];
            error_log("Atal Import: Item #{$item_index} - ACF keys: " . implode(', ', array_keys($acf)));

            // filtriranje po brandu (za rabljene)
            if ($filter && isset($acf['brand']) && stripos($acf['brand'], $filter) === false) {
                error_log("Atal Import: Item #{$item_index} - Skipped due to brand filter");
                continue;
            }

            $post_type = $item['type'] ?? 'post';
            $source_id = isset($item['id']) ? (int) $item['id'] : 0;

            // Debug: preveri source_id
            if ($source_id === 0) {
                error_log("Atal Import: WARNING - Item #{$item_index} has source_id = 0! Item structure: " . print_r($item, true));
            }

            // Za Polylang: Izlušči podatke za trenutni jezik iz ACF polj z jezikovnimi sufiksi
            // Primer: title_en, title_sl, text_en, text_sl
            $post_title = '';
            $post_content = '';

            // Debug: izpiši vse ACF ključe
            error_log("Atal Import: Item #{$item_index} - Available ACF keys: " . implode(', ', array_keys($acf)));

            // Poskusi dobiti naslov iz ACF polja z jezikovnim sufiksom
            $post_title = atal_get_acf_value_by_lang($acf, 'title', $lang);
            error_log("Atal Import: Item #{$item_index} - Extracted title from ACF for lang '{$lang}': " . ($post_title ?: 'EMPTY'));

            // Če ni naslova z jezikovnim sufiksom, poskusi iz standardnega title polja
            if (empty($post_title)) {
                if (isset($item['title']['rendered'])) {
                    $post_title = $item['title']['rendered'];
                    error_log("Atal Import: Item #{$item_index} - Using title from item['title']['rendered']: " . $post_title);
                } elseif (isset($item['title']) && is_string($item['title'])) {
                    $post_title = $item['title'];
                    error_log("Atal Import: Item #{$item_index} - Using title from item['title']: " . $post_title);
                } elseif (isset($item['post_title'])) {
                    $post_title = $item['post_title'];
                    error_log("Atal Import: Item #{$item_index} - Using title from item['post_title']: " . $post_title);
                }
            }

            // Če je naslov še vedno prazen ali je samo ime polja (npr. "title_en"), preskoči
            if (empty($post_title) || $post_title === 'title_' . $lang || $post_title === 'title') {
                error_log("Atal Import: WARNING - Item #{$item_index} - Title is empty or invalid: '{$post_title}'");
                // Poskusi dobiti naslov iz katerega koli jezika kot fallback
                foreach ($langs as $fallback_lang) {
                    $fallback_title = atal_get_acf_value_by_lang($acf, 'title', $fallback_lang);
                    if (!empty($fallback_title) && $fallback_title !== 'title_' . $fallback_lang && $fallback_title !== 'title') {
                        $post_title = $fallback_title;
                        error_log("Atal Import: Item #{$item_index} - Using fallback title from lang '{$fallback_lang}': " . $post_title);
                        break;
                    }
                }
            }

            // Poskusi dobiti vsebino iz ACF polja z jezikovnim sufiksom
            $post_content = atal_get_acf_value_by_lang($acf, 'text', $lang);

            // Če ni vsebine z jezikovnim sufiksom, poskusi iz standardnega text polja
            if (empty($post_content)) {
                $post_content = isset($acf['text']) ? $acf['text'] : '';
            }

            error_log("Atal Import: Item #{$item_index} - lang: {$lang}, title: " . substr($post_title, 0, 100) . ", content: " . substr($post_content, 0, 50));

            // Preveri, ali sta title in content prazna ali neveljavna
            if (empty($post_title) || $post_title === 'title_' . $lang || $post_title === 'title') {
                // Poskusi dobiti naslov iz drugega jezika kot fallback
                foreach ($langs as $fallback_lang) {
                    if ($fallback_lang !== $lang) {
                        $fallback_title = atal_get_acf_value_by_lang($acf, 'title', $fallback_lang);
                        if (!empty($fallback_title) && $fallback_title !== 'title_' . $fallback_lang && $fallback_title !== 'title') {
                            $post_title = $fallback_title;
                            error_log("Atal Import: Using fallback title from lang '{$fallback_lang}' for lang '{$lang}': '{$post_title}'");
                            break;
                        }
                    }
                }

                // Če je naslov še vedno prazen ali neveljaven, preskoči post (razen če je content prisoten)
                if (empty($post_title) || $post_title === 'title_' . $lang || $post_title === 'title') {
                    if (empty($post_content)) {
                        error_log("Atal Import: Skipping post - title and content are empty/invalid for lang '{$lang}' (title: '{$post_title}')");
                        continue;
                    }
                    // Če je content prisoten, uporabi fallback naslov
                    $post_title = 'Untitled (' . $lang . ')';
                    error_log("Atal Import: Using fallback title for lang '{$lang}': '{$post_title}'");
                }
            }

            // Če je content prazen, uporabi fallback
            if (empty($post_content)) {
                // Poskusi dobiti vsebino iz drugega jezika kot fallback
                foreach ($langs as $fallback_lang) {
                    if ($fallback_lang !== $lang) {
                        $fallback_content = atal_get_acf_value_by_lang($acf, 'text', $fallback_lang);
                        if (!empty($fallback_content)) {
                            $post_content = $fallback_content;
                            error_log("Atal Import: Using fallback content from lang '{$fallback_lang}' for lang '{$lang}'");
                            break;
                        }
                    }
                }
            }

            // Polylang: Poišči obstoječi post z istim source_id IN jezikom
            $existing_id = 0;

            if ($source_id) {
                $existing_id = atal_get_existing_post_id($source_id, $post_type, $lang);

                if ($existing_id) {
                    $existing_post = get_post($existing_id);
                    $existing_title = $existing_post->post_title;
                    error_log("Atal Import: Found existing post {$existing_id} with source_id {$source_id} and lang {$lang}, current title: '{$existing_title}'");
                } else {
                    error_log("Atal Import: No existing post found with source_id {$source_id} and lang {$lang}");
                }
            }

            // Polylang: Če post že obstaja, ga posodobi
            if ($existing_id) {
                error_log("Atal Import: Updating existing post {$existing_id} for lang {$lang}");

                // Posodobi post z direktnimi vrednostmi (brez večjezičnih formatov)
                $post_data = [
                    'ID' => $existing_id,
                    'post_title' => $post_title,
                    'post_content' => $post_content,
                ];
                wp_update_post($post_data);

                // Posodobi jezik posta (če se je spremenil ali ni nastavljen)
                $current_lang = '';
                if (function_exists('pll_get_post_language')) {
                    $current_lang = pll_get_post_language($existing_id);
                }
                if (empty($current_lang)) {
                    $current_lang = get_post_meta($existing_id, 'pll_language', true);
                }
                if (empty($current_lang)) {
                    $current_lang = get_post_meta($existing_id, '_atal_source_lang', true);
                }

                if ($current_lang !== $lang) {
                    if (function_exists('pll_set_post_language')) {
                        pll_set_post_language($existing_id, $lang);
                        error_log("Atal Import: Posodobljen jezik posta {$existing_id} iz '{$current_lang}' na '{$lang}'");
                    } else {
                        // Fallback: shrani v meta polje
                        update_post_meta($existing_id, 'pll_language', $lang);
                        error_log("Atal Import: Shranjen jezik posta {$existing_id} v meta polje: '{$lang}' (Polylang ni aktiviran)");
                    }
                } else {
                    error_log("Atal Import: Jezik posta {$existing_id} je že nastavljen na '{$lang}'");
                }

                // Pomembno: Posodobi translations tudi za obstoječi post
                // To zagotovi, da so posti pravilno povezani kot prevodi
                if ($source_id && function_exists('pll_save_post_translations')) {
                    $translations = [];
                    $all_posts = atal_get_all_posts_by_source_id($source_id, $post_type);

                    foreach ($all_posts as $linked_post_id) {
                        $linked_lang = pll_get_post_language($linked_post_id);
                        if (!empty($linked_lang)) {
                            $translations[$linked_lang] = $linked_post_id;
                        }
                    }

                    // Dodaj trenutni post v translations
                    $translations[$lang] = $existing_id;

                    // Shrani translations za vse poste
                    $save_result = pll_save_post_translations($translations);
                    if ($save_result) {
                        error_log("Atal Import: Translations posodobljene za obstoječi post {$existing_id}: " . print_r($translations, true));
                    } else {
                        error_log("Atal Import: WARNING - Failed to update translations for existing post {$existing_id}");
                    }
                }

                clean_post_cache($existing_id);

                $id = $existing_id;
                error_log("Atal Import: Updated post {$id} for lang {$lang}");
            } else {
                // Polylang: Ustvari nov post za ta jezik
                error_log("Atal Import: Creating new post for lang {$lang}, source_id: {$source_id}, title: {$post_title}");

                $post_data = [
                    'post_type' => $post_type,
                    'post_title' => $post_title,
                    'post_content' => $post_content,
                    'post_status' => 'publish',
                    'post_author' => 1,
                ];

                $id = wp_insert_post($post_data);

                if ($id > 0 && !is_wp_error($id)) {
                    // Shrani meta polja
                    update_post_meta($id, '_atal_source_id', (string) $source_id);
                    update_post_meta($id, '_atal_source_lang', $lang);

                    // Nastavi jezik posta za Polylang (to je ključno!)
                    $lang_set_result = atal_link_polylang_post($id, $lang, $source_id);
                    if (!$lang_set_result) {
                        error_log("Atal Import: WARNING - Failed to set language for post {$id} - Polylang may not be active");
                        // Vseeno shrani jezik v meta polje za referenco
                        update_post_meta($id, 'pll_language', $lang);
                    } else {
                        error_log("Atal Import: Successfully set language for post {$id} to '{$lang}'");
                    }

                    // Preveri, ali je jezik res nastavljen
                    $verify_lang = '';
                    if (function_exists('pll_get_post_language')) {
                        $verify_lang = pll_get_post_language($id);
                    }
                    if (empty($verify_lang)) {
                        $verify_lang = get_post_meta($id, 'pll_language', true);
                    }
                    if (empty($verify_lang)) {
                        $verify_lang = get_post_meta($id, '_atal_source_lang', true);
                    }
                    error_log("Atal Import: Verified language for post {$id}: '{$verify_lang}' (expected: '{$lang}')");

                    clean_post_cache($id);
                    error_log("Atal Import: Created new post {$id} for lang {$lang}");
                } else {
                    $error_msg = is_wp_error($id) ? $id->get_error_message() : 'Unknown error';
                    error_log("Atal Import: ERROR - Failed to create post: {$error_msg}");
                    continue;
                }
            }

            if (empty($id) || $id === 0) {
                error_log("Atal Import: Failed to create/update post - returned ID is 0 or empty. Title: {$post_title}, Lang: {$lang}");
                continue;
            }

            error_log("Atal Import: Successfully " . ($existing_id ? "updated" : "created") . " post ID {$id} for lang {$lang}");

            // Zapišemo source_id za dedup (če še ni nastavljen)
            if ($source_id) {
                $existing_source_id = get_post_meta($id, '_atal_source_id', true);
                if ($existing_source_id !== (string) $source_id) {
                    $meta_result = update_post_meta($id, '_atal_source_id', (string) $source_id);
                    if (!$meta_result && empty($existing_source_id)) {
                        // Če update ne deluje in meta ne obstaja, poskusi add
                        $meta_result = add_post_meta($id, '_atal_source_id', (string) $source_id, true);
                    }
                    if (!$meta_result) {
                        error_log("Atal Import: ERROR - Failed to save _atal_source_id for post {$id}. Existing: " . var_export($existing_source_id, true));
                    } else {
                        error_log("Atal Import: Successfully saved _atal_source_id {$source_id} for post {$id}");
                    }
                }
            }

            // Shrani tudi naš jezik za referenco
            update_post_meta($id, '_atal_source_lang', $lang);

            // taksonomija za nove jahte (brand)
            if ($post_type === 'new_yachts' && !empty($acf['brand'])) {
                $term = term_exists($acf['brand'], 'new_yacht_category');
                if (!$term || is_wp_error($term)) {
                    $term = wp_insert_term($acf['brand'], 'new_yacht_category');
                }
                if (!is_wp_error($term)) {
                    wp_set_object_terms($id, intval($term['term_id']), 'new_yacht_category');
                }
            }

            // Pridobi obstoječi seznam slik za ta post (za primerjavo)
            $existing_images = get_post_meta($id, '_atal_images_list', true);
            if (!is_array($existing_images)) {
                $existing_images = [];
            }

            // Pripravi nov seznam slik za ta post
            $new_images_list = [];

            // Polylang: Preveri, ali obstaja image polje v API odgovoru (tudi z jezikovnim sufiksom)
            $has_image_in_api = false;
            if (isset($acf['image_' . $lang]) && !empty($acf['image_' . $lang])) {
                $has_image_in_api = true;
            } elseif (isset($acf['image']) && !empty($acf['image'])) {
                $has_image_in_api = true;
            }

            // Polylang: meta polja (ACF) - shranjujemo direktno za vsak jezik
            // Ker imamo ločene poste za vsak jezik, lahko shranjujemo podatke direktno
            foreach ($acf as $key => $val) {
                // Sanitiziraj ključ
                $key = sanitize_key($key);
                if (empty($key))
                    continue;

                // Preveri, ali je to polje z jezikovnim sufiksom (npr. title_en, title_sl)
                // Če je, izlušči samo vrednost za trenutni jezik
                $base_key = $key;
                $field_lang = null;

                // Preveri, ali se ključ konča z jezikovnim sufiksom
                foreach ($langs as $check_lang) {
                    $lang_suffix = '_' . $check_lang;
                    if (substr($key, -strlen($lang_suffix)) === $lang_suffix) {
                        $base_key = substr($key, 0, -strlen($lang_suffix));
                        $field_lang = $check_lang;
                        break;
                    }
                }

                // Če je polje z jezikovnim sufiksom, shrani samo če je za trenutni jezik
                if ($field_lang !== null && $field_lang !== $lang) {
                    continue; // Preskoči polja za druge jezike
                }

                // Pomembno: Shrani polje Z jezikovnim sufiksom, ne brez njega
                // To omogoča YooTheme dostop do vseh jezikovnih variant
                // Filter v yootheme-polylang-integration.php bo izluščil pravilno vrednost
                if ($field_lang !== null && $field_lang === $lang) {
                    // Shrani z jezikovnim sufiksom (npr. title_en, title_sl)
                    $save_key = $key; // Ohrani originalni ključ z sufiksom
                } else {
                    // Polje brez jezikovnega sufiksa (npr. image, brand)
                    $save_key = $base_key;
                }

                // Preveri tip polja - image/file/gallery polja so array
                $is_image_field = is_array($val) && (isset($val['url']) || isset($val['ID']));
                $is_gallery_field = is_array($val) && isset($val[0]) && (is_array($val[0]) || is_numeric($val[0]));

                if (is_string($val)) {
                    // String vrednosti - sanitiziraj in shrani direktno
                    $val = sanitize_text_field($val);

                    error_log("Atal Import: Saving ACF field '{$save_key}' (string) for lang '{$lang}': '{$val}'");

                    // Shrani direktno v post meta (brez večjezičnih formatov)
                    update_post_meta($id, $save_key, $val);

                    // Opcijsko: Če je ACF nameščen, posodobi tudi preko ACF API-ja
                    if (function_exists('update_field') && function_exists('get_field_object')) {
                        $field_object = get_field_object($save_key, $id);
                        if ($field_object && !empty($field_object['key'])) {
                            update_field($field_object['key'], $val, $id);
                        }
                    }
                } elseif ($is_gallery_field) {
                    // ACF Gallery polje - array slik (za vse jezike enaka)
                    error_log("Atal Import: Processing gallery field '{$save_key}' for lang '{$lang}' with " . count($val) . " images");

                    $gallery_ids = atal_import_gallery_field($val, $id, $save_key, $lang);

                    if (!empty($gallery_ids)) {
                        // KLJUČNO: Uporabi ACF API za shranjevanje, če je ACF nameščen
                        // To zagotovi, da se galerija pravilno registrira kot ACF polje
                        $saved = false;
                        if (function_exists('update_field') && function_exists('get_field_object')) {
                            // Poskusi pridobiti ACF field key
                            $field_object = get_field_object($save_key, $id);
                            if ($field_object && isset($field_object['key'])) {
                                // Uporabi field key za shranjevanje - to je najbolj zanesljivo
                                update_field($field_object['key'], $gallery_ids, $id);
                                error_log("Atal Import: Saved gallery '{$save_key}' via ACF field key '{$field_object['key']}' with " . count($gallery_ids) . " images");
                                $saved = true;
                            } else {
                                // Field object še ne obstaja, uporabi field name
                                update_field($save_key, $gallery_ids, $id);
                                error_log("Atal Import: Saved gallery '{$save_key}' via ACF field name with " . count($gallery_ids) . " images");
                                $saved = true;
                            }
                        }

                        // Fallback: Če ACF ni nameščen ali update_field ni uspel
                        if (!$saved) {
                            // Shrani direktno kot post meta
                            update_post_meta($id, $save_key, $gallery_ids);
                            // Shrani tudi ACF meta ključ za kompatibilnost
                            update_post_meta($id, '_' . $save_key, 'field_' . md5($save_key));
                            error_log("Atal Import: Saved gallery '{$save_key}' via post meta with " . count($gallery_ids) . " images: " . implode(', ', $gallery_ids));
                        }
                    }
                } elseif ($is_image_field) {
                    // Polylang: Image/file polja (posamezna slika) - shrani direktno za trenutni jezik
                    // Ker imamo ločene poste za vsak jezik, lahko shranjujemo direktno

                    // Za ACF image polja moramo shraniti attachment ID (ne array)
                    // ACF pričakuje ID ali array z ID
                    $attachment_id = null;
                    $image_url = null;

                    // Pridobi attachment ID iz array
                    if (isset($val['ID']) && is_numeric($val['ID'])) {
                        $attachment_id = intval($val['ID']);
                    } elseif (isset($val['id']) && is_numeric($val['id'])) {
                        $attachment_id = intval($val['id']);
                    }

                    // Pridobi URL iz array
                    if (isset($val['url']) && !empty($val['url'])) {
                        $image_url = $val['url'];
                    }

                    // Preveri, ali attachment obstaja na podstrani
                    // Če ne, ga moramo uvesti iz URL-ja
                    $local_attachment_id = null;
                    if ($attachment_id) {
                        // Preveri, ali attachment obstaja na podstrani
                        $local_attachment = get_post($attachment_id);
                        if ($local_attachment && $local_attachment->post_type === 'attachment') {
                            $local_attachment_id = $attachment_id;
                        }
                    }

                    // Če attachment ne obstaja in imamo URL, ga uvozi
                    if (!$local_attachment_id && $image_url) {
                        // Optimizacija: Preveri, ali slika že obstaja na podstrani (po URL-ju ali filename)
                        $filename = basename(parse_url($image_url, PHP_URL_PATH));
                        $existing_attachment = get_posts([
                            'post_type' => 'attachment',
                            'post_status' => 'any',
                            'posts_per_page' => 1,
                            'meta_query' => [
                                [
                                    'key' => '_atal_source_image_url',
                                    'value' => $image_url,
                                    'compare' => '='
                                ]
                            ],
                            'fields' => 'ids'
                        ]);

                        if (!empty($existing_attachment)) {
                            $local_attachment_id = $existing_attachment[0];
                            error_log("Atal Import: Found existing attachment ID {$local_attachment_id} for URL '{$image_url}'");
                        } else {
                            // Slika ne obstaja, uvozi jo
                            require_once(ABSPATH . 'wp-admin/includes/media.php');
                            require_once(ABSPATH . 'wp-admin/includes/file.php');
                            require_once(ABSPATH . 'wp-admin/includes/image.php');

                            // Uvozi sliko iz URL-ja z timeout handling
                            $tmp = download_url($image_url, 300); // 5 minut timeout

                            if (!is_wp_error($tmp)) {
                                $file_array = [
                                    'name' => $filename,
                                    'tmp_name' => $tmp
                                ];

                                $local_attachment_id = media_handle_sideload($file_array, $id);

                                if (!is_wp_error($local_attachment_id)) {
                                    // Shrani source URL v meta polje za prihodnje preverjanje
                                    update_post_meta($local_attachment_id, '_atal_source_image_url', $image_url);
                                    error_log("Atal Import: Successfully imported image from URL '{$image_url}' as attachment ID {$local_attachment_id}");
                                } else {
                                    error_log("Atal Import: Failed to import image from URL '{$image_url}': " . $local_attachment_id->get_error_message());
                                    $local_attachment_id = null;
                                }
                            } else {
                                error_log("Atal Import: Failed to download image from URL '{$image_url}': " . $tmp->get_error_message());
                            }
                        }
                    }

                    // Polylang: Shrani attachment ID direktno v post meta (brez jezik-specifičnih polj)
                    if ($local_attachment_id) {
                        // Shrani direktno v post meta
                        update_post_meta($id, $save_key, $local_attachment_id);

                        // Dodaj v nov seznam slik (shrani URL in attachment ID)
                        if ($image_url) {
                            $new_images_list[] = [
                                'url' => $image_url,
                                'attachment_id' => $local_attachment_id,
                                'lang' => $lang,
                                'field' => $save_key
                            ];
                        }
                    }

                    error_log("Atal Import: Saving ACF field '{$save_key}' (image/array) for lang '{$lang}': " . print_r($val, true));
                    error_log("Atal Import: Attachment ID for '{$save_key}' lang '{$lang}': " . ($local_attachment_id ?: 'NOT FOUND'));

                    // Opcijsko: Če je ACF nameščen, posodobi tudi preko ACF API-ja
                    if (function_exists('update_field') && function_exists('get_field_object') && $local_attachment_id) {
                        $field_object = get_field_object($save_key, $id);
                        if ($field_object && !empty($field_object['key'])) {
                            update_field($field_object['key'], $local_attachment_id, $id);
                        }
                    }
                } elseif (is_array($val)) {
                    // Polylang: Druga array polja - shrani direktno
                    $val = array_map('sanitize_text_field', $val);
                    update_post_meta($id, $save_key, $val);

                    error_log("Atal Import: Saving ACF field '{$save_key}' (array) for lang '{$lang}': " . print_r($val, true));

                    if (function_exists('update_field') && function_exists('get_field_object')) {
                        $field_object = get_field_object($save_key, $id);
                        if ($field_object && !empty($field_object['key'])) {
                            update_field($field_object['key'], $val, $id);
                        }
                    }
                } elseif (is_numeric($val)) {
                    // Polylang: Numerične vrednosti - shrani direktno
                    $val = is_float($val) ? floatval($val) : intval($val);
                    update_post_meta($id, $save_key, $val);

                    error_log("Atal Import: Saving ACF field '{$save_key}' (numeric) for lang '{$lang}': {$val}");

                    if (function_exists('update_field') && function_exists('get_field_object')) {
                        $field_object = get_field_object($save_key, $id);
                        if ($field_object && !empty($field_object['key'])) {
                            update_field($field_object['key'], $val, $id);
                        }
                    }
                }

                // Registriraj polje v REST API
                register_post_meta($post_type, $save_key, [
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                    'auth_callback' => '__return_true',
                ]);
            }

            // Shrani nov seznam slik za ta post
            // Pomembno: združi z obstoječimi slikami iz drugih jezikov
            // Ne prepišemo vseh slik, ampak dodamo/posodobimo samo za trenutni jezik
            $merged_images_list = $existing_images;

            // Odstrani stare slike za trenutni jezik iz seznama
            $merged_images_list = array_filter($merged_images_list, function ($img) use ($lang) {
                return !isset($img['lang']) || $img['lang'] !== $lang;
            });

            // Dodaj nove slike za trenutni jezik
            $merged_images_list = array_merge($merged_images_list, $new_images_list);

            // Shrani združen seznam
            update_post_meta($id, '_atal_images_list', $merged_images_list);

            // Primerjaj stare in nove slike ZA TRENUTNI JEZIK ter pobriši tiste, ki jih ni več
            // Pomembno: primerjamo samo za trenutni jezik, ne za vse jezike
            $existing_images_for_lang = array_filter($existing_images, function ($img) use ($lang) {
                return isset($img['lang']) && $img['lang'] === $lang;
            });

            error_log("Atal Import: Post {$id}, lang {$lang} - has_image_in_api: " . ($has_image_in_api ? 'YES' : 'NO') . ", existing_images_for_lang: " . count($existing_images_for_lang) . ", new_images_list: " . count($new_images_list));
            error_log("Atal Import: Post {$id} - existing_images total: " . count($existing_images) . ", existing_images structure: " . print_r($existing_images, true));

            // Polylang: Če image polje ni v API odgovoru, pobriši sliko iz post meta
            if (!$has_image_in_api) {
                error_log("Atal Import: Image field not in API response for post {$id}, lang {$lang}");

                // Preveri, ali obstaja slika v meta polju
                $main_image_id = get_post_meta($id, 'image', true);

                // Če obstaja attachment ID, ga moramo pobrisati
                if ($main_image_id) {
                    error_log("Atal Import: Found image: {$main_image_id} - will delete");

                    // Preveri, ali se ta attachment še uporablja v drugih postih
                    $attachment_used = get_posts([
                        'post_type' => ['new_yachts', 'used_yachts'],
                        'posts_per_page' => 1,
                        'meta_query' => [
                            [
                                'key' => 'image',
                                'value' => $main_image_id,
                                'compare' => '='
                            ]
                        ],
                        'fields' => 'ids',
                        'post__not_in' => [$id] // Izključi trenutni post
                    ]);

                    // Če se attachment ne uporablja v drugih postih, ga pobriši
                    if (empty($attachment_used)) {
                        // Pobriši attachment (ne briši fizične datoteke, samo post)
                        wp_delete_attachment($main_image_id, true);
                        error_log("Atal Import: Deleted unused attachment ID {$main_image_id} for lang {$lang}");
                    } else {
                        error_log("Atal Import: Keeping attachment ID {$main_image_id} (still used in other posts)");
                    }

                    // Pobriši attachment ID iz meta polja
                    delete_post_meta($id, 'image');
                    error_log("Atal Import: Deleted meta 'image' for attachment ID {$main_image_id}");

                    // Pobriši tudi iz ACF polja, če je ACF nameščen
                    if (function_exists('update_field') && function_exists('get_field_object')) {
                        $field_object = get_field_object('image', $id);
                        if ($field_object && !empty($field_object['key'])) {
                            // Pobriši ACF polje - uporabi false ali null namesto praznega stringa
                            update_field($field_object['key'], false, $id);
                            // Pobriši tudi direktno iz meta polja
                            delete_post_meta($id, $field_object['name']);
                            error_log("Atal Import: Cleared ACF field 'image'");
                        } else {
                            error_log("Atal Import: ACF field object not found for 'image' field");
                            // Poskusi pobrisati direktno iz meta polja
                            delete_post_meta($id, 'image');
                            error_log("Atal Import: Deleted meta 'image' directly (ACF field object not found)");
                        }
                    } else {
                        // Če ACF ni nameščen, pobriši direktno iz meta polja
                        delete_post_meta($id, 'image');
                        error_log("Atal Import: Deleted meta 'image' directly (ACF not installed)");
                    }
                } else {
                    error_log("Atal Import: No image found for post {$id}, lang {$lang}");
                }
            } elseif (!empty($existing_images_for_lang) || !empty($existing_images)) {
                // Če image polje JE v API odgovoru, primerjaj stare in nove slike
                // Pridobi URL-je iz obstoječih slik za trenutni jezik
                $existing_urls = [];
                if (!empty($existing_images_for_lang)) {
                    $existing_urls = array_map(function ($img) {
                        return isset($img['url']) ? $img['url'] : null;
                    }, $existing_images_for_lang);
                    $existing_urls = array_filter($existing_urls);
                }

                // Pridobi URL-je iz novih slik za trenutni jezik
                $new_urls = [];
                if (!empty($new_images_list)) {
                    $new_urls = array_map(function ($img) {
                        return isset($img['url']) ? $img['url'] : null;
                    }, $new_images_list);
                    $new_urls = array_filter($new_urls);
                }

                // Poišči slike, ki jih ni več v novem seznamu ZA TRENUTNI JEZIK
                // Če je new_images_list prazen, so vse obstoječe slike odstranjene
                $removed_urls = array_diff($existing_urls, $new_urls);

                if (!empty($removed_urls)) {
                    error_log("Atal Import: Found " . count($removed_urls) . " removed images for post {$id}, lang {$lang}");

                    // Poišči attachment ID-je za odstranjene slike
                    foreach ($existing_images_for_lang as $existing_img) {
                        if (isset($existing_img['url']) && in_array($existing_img['url'], $removed_urls)) {
                            $removed_attachment_id = isset($existing_img['attachment_id']) ? $existing_img['attachment_id'] : null;

                            if ($removed_attachment_id) {
                                // Polylang: Preveri, ali se ta attachment še uporablja v drugih postih
                                $attachment_used = get_posts([
                                    'post_type' => ['new_yachts', 'used_yachts'],
                                    'posts_per_page' => 1,
                                    'meta_query' => [
                                        [
                                            'key' => 'image',
                                            'value' => $removed_attachment_id,
                                            'compare' => '='
                                        ]
                                    ],
                                    'fields' => 'ids',
                                    'post__not_in' => [$id] // Izključi trenutni post
                                ]);

                                // Če se attachment ne uporablja v drugih postih, ga pobriši
                                if (empty($attachment_used)) {
                                    // Pobriši attachment (ne briši fizične datoteke, samo post)
                                    wp_delete_attachment($removed_attachment_id, true);
                                    error_log("Atal Import: Deleted unused attachment ID {$removed_attachment_id} (URL: {$existing_img['url']}) for lang {$lang}");
                                } else {
                                    error_log("Atal Import: Keeping attachment ID {$removed_attachment_id} (still used in other posts)");
                                }

                                // Pobriši attachment ID iz meta polja
                                $main_image_id = get_post_meta($id, 'image', true);
                                if ($main_image_id == $removed_attachment_id) {
                                    delete_post_meta($id, 'image');
                                    error_log("Atal Import: Deleted meta 'image' for attachment ID {$removed_attachment_id}");
                                }

                                // Pobriši tudi iz ACF polja, če je ACF nameščen
                                if (function_exists('update_field') && function_exists('get_field_object')) {
                                    $field_object = get_field_object('image', $id);
                                    if ($field_object && !empty($field_object['key'])) {
                                        // Pobriši ACF polje - uporabi false ali null namesto praznega stringa
                                        update_field($field_object['key'], false, $id);
                                        // Pobriši tudi direktno iz meta polja
                                        delete_post_meta($id, $field_object['name']);
                                        error_log("Atal Import: Cleared ACF field 'image'");
                                    } else {
                                        error_log("Atal Import: ACF field object not found for 'image' field");
                                        // Poskusi pobrisati direktno iz meta polja
                                        delete_post_meta($id, 'image');
                                        error_log("Atal Import: Deleted meta 'image' directly (ACF field object not found)");
                                    }
                                } else {
                                    // Če ACF ni nameščen, pobriši direktno iz meta polja
                                    delete_post_meta($id, 'image');
                                    error_log("Atal Import: Deleted meta 'image' directly (ACF not installed)");
                                }
                            }
                        }
                    }
                }
            }

            // Log samo v error_log, ne v datoteko
            error_log("Atal Import: " . ($existing_id ? "Updated" : "Inserted") . " post ID $id ($post_title)");
        }

        $log .= strtoupper($lang) . ": " . count($data) . " postov obdelanih\n";
    }

    update_option('atal_import_log', $log);
    error_log("Atal Import: === Import finished ===\n$log");

    // Avtomatsko sinhroniziraj ACF field groups po uvozu
    if (function_exists('atal_sync_acf_field_groups')) {
        $sync_result = atal_sync_acf_field_groups();
        if ($sync_result) {
            error_log("Atal Import: ACF field groups synchronized successfully");
        } else {
            error_log("Atal Import: ACF field groups synchronization failed or skipped");
        }
    }

    // Trigger custom action za dodatne akcije po uvozu
    do_action('atal_import_after_process');

    return ['status' => 'ok', 'log' => $log];
}


/**
 * Handle Push Sync from Master
 * 
 * @param array $params Payload from Master (type, data)
 * @return array Result
 */
function atal_import_handle_push($params)
{
    if (!isset($params['type']) || !isset($params['data'])) {
        return ['success' => false, 'message' => 'Invalid payload structure'];
    }

    $type = $params['type'];
    $data = $params['data'];
    $slug = $data['slug'] ?? '';

    // Log start
    error_log("Atal Push: Starting push sync for type '{$type}', slug '{$slug}'");

    if (empty($slug)) {
        return ['success' => false, 'message' => 'Missing slug'];
    }

    // Determine Post Type
    $postType = 'post'; // Default
    if ($type === 'news') {
        // Check if 'news' CPT exists, otherwise fallback to 'post'
        // Ideally we should use the configured CPT for news
        $postType = 'news';
        if (!post_type_exists('news')) {
            $postType = 'post';
            error_log("Atal Push: 'news' CPT not found, falling back to 'post'");
        }
    } else if ($type === 'new') {
        $postType = 'new_yachts';
    } else if ($type === 'used') {
        $postType = 'used_yachts';
    }

    // Verify post type exists
    if (!post_type_exists($postType)) {
        return ['success' => false, 'message' => "Post type '{$postType}' does not exist on this site"];
    }

    // Find existing post by slug
    $args = [
        'name' => $slug,
        'post_type' => $postType,
        'post_status' => 'any',
        'numberposts' => 1
    ];
    $posts = get_posts($args);
    $postId = $posts ? $posts[0]->ID : 0;

    // Prepare Post Data
    $postData = [
        'post_title' => $data['title'],
        'post_content' => $data['content'],
        'post_excerpt' => $data['excerpt'] ?? '',
        'post_status' => 'publish',
        'post_type' => $postType,
    ];

    // Valid date?
    if (!empty($data['published_at'])) {
        $postData['post_date'] = date('Y-m-d H:i:s', strtotime($data['published_at']));
    }

    // Insert or Update
    if ($postId) {
        $postData['ID'] = $postId;
        $resultId = wp_update_post($postData);
        error_log("Atal Push: Updated existing post ID {$postId}");
    } else {
        $postData['post_name'] = $slug; // slug only on creation to avoid conflicts
        $resultId = wp_insert_post($postData);
        $postId = $resultId;
        error_log("Atal Push: Created new post ID {$postId}");
    }

    if (is_wp_error($resultId)) {
        return ['success' => false, 'message' => $resultId->get_error_message()];
    }

    // Handle Custom Fields
    if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
        foreach ($data['custom_fields'] as $key => $value) {
            // Skip if value is null
            if ($value === null)
                continue;

            // If value is array, serialize it? WP does it automatically.
            update_post_meta($postId, $key, $value);

            // If ACF is active, also update via reference
            if (function_exists('update_field')) {
                update_field($key, $value, $postId);
            }
        }
    }

    // Handle Featured Image
    if (!empty($data['featured_image'])) {
        atal_import_attach_image($postId, $data['featured_image']);
    }

    // Handle Taxonomies
    if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
        foreach ($data['taxonomies'] as $fieldKey => $taxData) {
            // $fieldKey is typically the taxonomy name (e.g., 'category', 'news_category')
            // $taxData contains 'term' (Label) and 'translations' (Array of lang => label)

            $taxonomy = $fieldKey;

            // Map 'type' field from Master to 'category' on WP if post type is 'post'
            if ($postType === 'post' && $fieldKey === 'type') {
                $taxonomy = 'category';
            }

            if (!taxonomy_exists($taxonomy)) {
                error_log("Atal Push: Taxonomy '{$taxonomy}' does not exist, skipping");
                continue;
            }

            $termLabel = $taxData['term'] ?? '';
            if (empty($termLabel))
                continue;

            $translations = $taxData['translations'] ?? [];

            // Sync Term (create/find term ID)
            $termId = atal_sync_term($taxonomy, $termLabel);

            if ($termId) {
                // Assign term to post
                wp_set_object_terms($postId, [(int) $termId], $taxonomy);
                error_log("Atal Push: Assigned term '{$termLabel}' (ID: {$termId}) to post {$postId}");

                // Handle Falang Translations
                foreach ($translations as $langCode => $transLabel) {
                    atal_add_falang_translation($termId, $langCode, $transLabel, 'term');
                }
            }
        }
    }

    return ['success' => true, 'id' => $postId];
}

/**
 * Sync Term (Create if not exists)
 * 
 * @param string $taxonomy Taxonomy Slug
 * @param string $termLabel Term Name
 * @return int|false Term ID or false
 */
function atal_sync_term($taxonomy, $termLabel)
{
    if (empty($termLabel))
        return false;

    // Check if exists
    $term = get_term_by('name', $termLabel, $taxonomy);
    if ($term) {
        return $term->term_id;
    }

    // Insert
    $result = wp_insert_term($termLabel, $taxonomy);
    if (is_wp_error($result)) {
        error_log("Atal Push: Error creating term '{$termLabel}': " . $result->get_error_message());
        return false;
    }

    return $result['term_id'];
}

/**
 * Attach Image from URL as Featured Image
 * 
 * @param int $postId
 * @param string $url
 */
function atal_import_attach_image($postId, $url)
{
    if (empty($url))
        return;

    // Start by checking if we already have this image imported (dedup by Source URL meta)
    $existing = get_posts([
        'post_type' => 'attachment',
        'meta_key' => '_atal_source_image_url',
        'meta_value' => $url,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ]);

    $attachId = !empty($existing) ? $existing[0] : null;

    if (!$attachId) {
        // Need to download
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            error_log("Atal Push: Error downloading image '{$url}': " . $tmp->get_error_message());
            return;
        }

        $fileArray = [
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp
        ];

        $attachId = media_handle_sideload($fileArray, $postId);

        if (is_wp_error($attachId)) {
            error_log("Atal Push: Error sideloading image: " . $attachId->get_error_message());
            @unlink($tmp);
            return;
        }

        // Mark source
        update_post_meta($attachId, '_atal_source_image_url', $url);
    }

    if ($attachId) {
        set_post_thumbnail($postId, $attachId);
    }
}

/**
 * Get Falang Locale from Slug (e.g. 'sl' -> 'sl_SI', 'en' -> 'en_US')
 */
function atal_get_falang_locale($slug)
{
    if (!class_exists('Falang\Model\Falang_Model')) {
        // Fallback mapping if class not available
        $map = [
            'sl' => 'sl_SI',
            'en' => 'en_US',
            'de' => 'de_DE',
            'it' => 'it_IT',
            'hr' => 'hr_HR',
            'fr' => 'fr_FR'
        ];
        return $map[$slug] ?? $slug . '_' . strtoupper($slug);
    }

    try {
        $model = new Falang\Model\Falang_Model();
        $language = $model->get_language_by_slug($slug);
        return $language->locale ?? null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Add Falang Translation for a Term (Meta-based)
 * Follows pattern from falang-helpers.php
 * 
 * @param int $originalId The term ID
 * @param string $langCode Language code (e.g. 'sl', 'en', 'de')
 * @param string $translatedValue The translated label
 * @param string $type Object type ('term' supported)
 */
function atal_add_falang_translation($originalId, $langCode, $translatedValue, $type = 'term')
{
    if (empty($translatedValue))
        return;

    // 1. Get Locale
    $locale = atal_get_falang_locale($langCode);
    if (!$locale) {
        error_log("Atal Push: Could not find locale for language '$langCode'");
        return;
    }

    // 2. Construct Meta Key Prefix (e.g., '_en_US_')
    $prefix = '_' . $locale . '_';

    if ($type === 'term') {
        // Falang for Terms usually translates 'name', 'description'
        // Meta key: _en_US_name
        $meta_key = $prefix . 'name';

        update_term_meta($originalId, $meta_key, $translatedValue);

        // Mark as published
        update_term_meta($originalId, $prefix . 'published', 1);

        error_log("Atal Push: Saved meta translation for term {$originalId} ({$locale}): name");
    }
}


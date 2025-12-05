<?php
/**
 * Avtomatska sinhronizacija ACF field groupov iz glavne strani
 * To omogoča, da se ACF polja avtomatsko registrirajo na podstraneh
 */

if (!defined('ABSPATH')) exit;

/**
 * Ustvari ACF field group iz definicije
 */
function atal_create_acf_field_group($field_group_data) {
    // Preveri, ali je ACF nameščen
    if (!function_exists('acf_get_field_groups')) {
        error_log('Atal ACF Sync: ACF plugin is not installed or activated');
        return false;
    }
    
    if (!function_exists('acf_update_field_group') && !function_exists('acf_add_local_field_group')) {
        error_log('Atal ACF Sync: ACF functions not available');
        return false;
    }
    
    try {
        // Preveri, ali field group že obstaja po ključu
        $existing_group = false;
        if (function_exists('acf_get_field_group')) {
            $existing_group = acf_get_field_group($field_group_data['key']);
        } else {
            // Alternativa - poišči po ključu
            $all_groups = acf_get_field_groups();
            foreach ($all_groups as $group) {
                if (isset($group['key']) && $group['key'] === $field_group_data['key']) {
                    $existing_group = $group;
                    break;
                }
            }
        }
        
        // ACF pričakuje, da so polja vključena direktno v strukturo field group-a
        // Uporabimo acf_update_field_group (ne acf_add_local_field_group, ker lokalni se ne prikažejo v adminu)
        if ($existing_group && isset($existing_group['ID'])) {
            // Field group že obstaja, posodobi ga z vsemi polji
            $field_group_data['ID'] = $existing_group['ID'];
            error_log('Atal ACF Sync: Updating existing field group ' . $field_group_data['title'] . ' (ID: ' . $existing_group['ID'] . ') with ' . count($field_group_data['fields']) . ' fields');
            
            if (function_exists('acf_update_field_group')) {
                $result = acf_update_field_group($field_group_data);
                if ($result) {
                    error_log('Atal ACF Sync: Successfully updated field group ' . $field_group_data['title']);
                } else {
                    error_log('Atal ACF Sync: Failed to update field group ' . $field_group_data['title']);
                    return false;
                }
            } else {
                error_log('Atal ACF Sync: acf_update_field_group function not available');
                return false;
            }
        } else {
            // Ustvari nov field group z vsemi polji naenkrat
            // Pomembno: Uporabimo acf_update_field_group, ne acf_add_local_field_group
            // Lokalni field group-i se ne prikažejo v adminu!
            error_log('Atal ACF Sync: Creating new field group ' . $field_group_data['title'] . ' with ' . count($field_group_data['fields']) . ' fields');
            
            // Preveri strukturo polj
            foreach ($field_group_data['fields'] as $idx => $field) {
                error_log('Atal ACF Sync: Field ' . ($idx + 1) . ': ' . $field['name'] . ' (key: ' . $field['key'] . ', parent: ' . (isset($field['parent']) ? $field['parent'] : 'none') . ')');
            }
            
            // Uporabimo acf_update_field_group za ustvarjanje v bazi (vidno v adminu)
            if (function_exists('acf_update_field_group')) {
                // acf_update_field_group lahko ustvari nov field group, če ni ID-ja
                $result = acf_update_field_group($field_group_data);
                if ($result) {
                    error_log('Atal ACF Sync: Successfully created field group using acf_update_field_group');
                } else {
                    error_log('Atal ACF Sync: acf_update_field_group returned false - trying alternative method');
                    
                    // Alternativa: Najprej ustvari brez polj, nato dodaj polja
                    $group_without_fields = $field_group_data;
                    $fields_backup = $group_without_fields['fields'];
                    unset($group_without_fields['fields']);
                    
                    // Ustvari field group brez polj
                    $temp_result = acf_update_field_group($group_without_fields);
                    if ($temp_result) {
                        // Pridobi ID
                        $created_group = acf_get_field_group($field_group_data['key']);
                        if ($created_group && isset($created_group['ID'])) {
                            $field_group_data['ID'] = $created_group['ID'];
                            // Sedaj posodobi z polji
                            $field_group_data['fields'] = $fields_backup;
                            $result = acf_update_field_group($field_group_data);
                            if ($result) {
                                error_log('Atal ACF Sync: Successfully created field group using two-step method');
                            } else {
                                error_log('Atal ACF Sync: Failed to add fields to field group');
                                return false;
                            }
                        } else {
                            error_log('Atal ACF Sync: Failed to retrieve created field group ID');
                            return false;
                        }
                    } else {
                        error_log('Atal ACF Sync: Failed to create field group');
                        return false;
                    }
                }
            } else {
                error_log('Atal ACF Sync: acf_update_field_group function not available');
                return false;
            }
        }
        
        // Preveri, ali so polja pravilno dodana
        $created_group = acf_get_field_group($field_group_data['key']);
        if ($created_group) {
            $group_id = isset($created_group['ID']) ? $created_group['ID'] : 'unknown';
            error_log('Atal ACF Sync: Field group found with ID: ' . $group_id);
            
            if (function_exists('acf_get_fields')) {
                $fields = acf_get_fields($created_group['ID']);
                error_log('Atal ACF Sync: Field group now contains ' . count($fields) . ' fields');
                if (!empty($fields)) {
                    foreach ($fields as $field) {
                        error_log('Atal ACF Sync: Found field: ' . $field['name'] . ' (key: ' . $field['key'] . ')');
                    }
                } else {
                    error_log('Atal ACF Sync: WARNING - Field group created but contains no fields!');
                }
            }
        } else {
            error_log('Atal ACF Sync: ERROR - Field group not found after creation!');
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Atal ACF Sync: Error creating field group: ' . $e->getMessage());
        return false;
    }
}

// Opomba: Funkcija atal_create_acf_field ni več potrebna,
// ker ACF pričakuje polne field definicije direktno v field group strukturi

/**
 * Sinhroniziraj ACF field groups iz glavne strani
 */
function atal_sync_acf_field_groups() {
    // Preveri, ali je ACF nameščen
    if (!function_exists('acf_get_field_groups')) {
        error_log('Atal ACF Sync: ACF plugin is not installed or activated');
        return false;
    }
    
    $url = get_option('atal_import_url');
    if (empty($url)) {
        error_log('Atal ACF Sync: Import URL is not set');
        return false;
    }
    
    if (!defined('ATAL_IMPORT_API_KEY')) {
        error_log('Atal ACF Sync: ATAL_IMPORT_API_KEY is not defined');
        return false;
    }
    
    try {
        // Pridobi strukturo field groupov iz glavne strani
        $export_url = str_replace('/wp-json/atal-sync/v1/export', '/wp-json/atal-sync/v1/export-fields', $url);
        $export_url = add_query_arg('key', ATAL_IMPORT_API_KEY, $export_url);
        
        error_log('Atal ACF Sync: Fetching field groups from: ' . $export_url);
        
        $response = wp_remote_get($export_url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            error_log('Atal ACF Sync: Error fetching field groups: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            error_log('Atal ACF Sync: Invalid JSON response from main site. Response: ' . substr($body, 0, 500));
            return false;
        }
        
        if (!isset($data['field_groups'])) {
            error_log('Atal ACF Sync: Missing field_groups in response. Response keys: ' . implode(', ', array_keys($data)));
            return false;
        }
    
        $field_groups_data = $data['field_groups'];
        $created_count = 0;
        
        error_log('Atal ACF Sync: Starting sync process. Field groups data keys: ' . implode(', ', array_keys($field_groups_data)));
        
        if (!is_array($field_groups_data)) {
            error_log('Atal ACF Sync: field_groups is not an array');
            return false;
        }
        
        // Zbrani vsi field groups, ki jih moramo ustvariti
        // Struktura: [group_key => [group_data, post_types]]
        $groups_to_create = [];
        
        foreach ($field_groups_data as $post_type => $field_groups) {
            if (!is_array($field_groups)) {
                error_log('Atal ACF Sync: Field groups for ' . $post_type . ' is not an array');
                continue;
            }
            
            error_log('Atal ACF Sync: Processing ' . count($field_groups) . ' field groups for post type ' . $post_type);
            
            foreach ($field_groups as $group_data) {
                if (!isset($group_data['key']) || !isset($group_data['title'])) {
                    error_log('Atal ACF Sync: Invalid group data structure. Keys: ' . implode(', ', array_keys($group_data)));
                    continue;
                }
                
                $group_key = $group_data['key'];
                $fields_count = isset($group_data['fields']) && is_array($group_data['fields']) ? count($group_data['fields']) : 0;
                error_log('Atal ACF Sync: Processing group ' . $group_data['title'] . ' (key: ' . $group_key . ') with ' . $fields_count . ' fields');
                
                // Če group še ni v seznamu, ga dodaj
                if (!isset($groups_to_create[$group_key])) {
                    $groups_to_create[$group_key] = [
                        'group_data' => $group_data,
                        'post_types' => []
                    ];
                }
                
                // Dodaj post type v seznam za ta group
                if (!in_array($post_type, $groups_to_create[$group_key]['post_types'])) {
                    $groups_to_create[$group_key]['post_types'][] = $post_type;
                }
            }
        }
        
        error_log('Atal ACF Sync: Total groups to create: ' . count($groups_to_create));
        
        if (empty($groups_to_create)) {
            error_log('Atal ACF Sync: No field groups to create');
            return false;
        }
        
        // Ustvari vsak field group z vsemi njegovimi polji in location rules
        foreach ($groups_to_create as $group_key => $group_info) {
            $group_data = $group_info['group_data'];
            $post_types = $group_info['post_types'];
            
            if (!isset($group_data['fields']) || !is_array($group_data['fields'])) {
                error_log('Atal ACF Sync: No fields found for group ' . $group_data['title']);
                continue;
            }
            
            // Ustvari location rules za vse post type (OR logika - vsak post type v svoji skupini)
            // V ACF: skupine so povezane z OR, pravila v skupini z AND
            // Za OR logiko moramo imeti vsak post type v svoji skupini
            $location_groups = [];
            foreach ($post_types as $pt) {
                $location_groups[] = [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => $pt,
                    ],
                ];
            }
            
            // Ustvari polja direktno v field group strukturi
            // ACF pričakuje polne field definicije z vsemi potrebnimi lastnostmi
            $fields_array = [];
            foreach ($group_data['fields'] as $field_def) {
                if (!isset($field_def['name'])) {
                    error_log('Atal ACF Sync: Field definition missing name');
                    continue;
                }
                
                // Uporabi original field key iz glavne strani, če je na voljo
                // Drugače ustvari nov key
                $field_key = isset($field_def['key']) ? $field_def['key'] : 'field_atal_' . md5($group_key . '_' . $field_def['name']);
                
                $field = [
                    'key' => $field_key,
                    'label' => isset($field_def['label']) ? $field_def['label'] : ucfirst(str_replace('_', ' ', $field_def['name'])),
                    'name' => $field_def['name'],
                    'type' => isset($field_def['type']) ? $field_def['type'] : 'text',
                    'required' => isset($field_def['required']) ? (bool) $field_def['required'] : false,
                    'default_value' => isset($field_def['default_value']) ? $field_def['default_value'] : '',
                    'instructions' => isset($field_def['instructions']) ? $field_def['instructions'] : '',
                    'menu_order' => isset($field_def['menu_order']) ? (int) $field_def['menu_order'] : 0,
                    'parent' => $group_key, // Za začetek nastavimo na key, pozneje popravimo na ID
                    'wrapper' => isset($field_def['wrapper']) ? $field_def['wrapper'] : [
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ],
                    'placeholder' => isset($field_def['placeholder']) ? $field_def['placeholder'] : '',
                    'prepend' => isset($field_def['prepend']) ? $field_def['prepend'] : '',
                    'append' => isset($field_def['append']) ? $field_def['append'] : '',
                    'maxlength' => isset($field_def['maxlength']) ? $field_def['maxlength'] : '',
                ];
                
                // Dodaj dodatne lastnosti glede na tip field-a
                // Select field - choices (KLJUČNO!)
                if (isset($field_def['type']) && $field_def['type'] === 'select') {
                    if (isset($field_def['choices']) && is_array($field_def['choices'])) {
                        $field['choices'] = $field_def['choices'];
                    }
                    $field['allow_null'] = isset($field_def['allow_null']) ? (bool) $field_def['allow_null'] : false;
                    $field['multiple'] = isset($field_def['multiple']) ? (bool) $field_def['multiple'] : false;
                    $field['ui'] = isset($field_def['ui']) ? (bool) $field_def['ui'] : false;
                    $field['return_format'] = isset($field_def['return_format']) ? $field_def['return_format'] : 'value';
                }
                
                // Textarea field
                if (isset($field_def['type']) && $field_def['type'] === 'textarea') {
                    $field['rows'] = isset($field_def['rows']) ? (int) $field_def['rows'] : 4;
                    $field['new_lines'] = isset($field_def['new_lines']) ? $field_def['new_lines'] : '';
                }
                
                // Number field
                if (isset($field_def['type']) && $field_def['type'] === 'number') {
                    $field['min'] = isset($field_def['min']) ? $field_def['min'] : '';
                    $field['max'] = isset($field_def['max']) ? $field_def['max'] : '';
                    $field['step'] = isset($field_def['step']) ? $field_def['step'] : '';
                }
                
                // Image/File field
                if (isset($field_def['type']) && in_array($field_def['type'], ['image', 'file'])) {
                    $field['return_format'] = isset($field_def['return_format']) ? $field_def['return_format'] : 'array';
                    $field['preview_size'] = isset($field_def['preview_size']) ? $field_def['preview_size'] : 'medium';
                    $field['library'] = isset($field_def['library']) ? $field_def['library'] : 'all';
                }
                
                // Gallery field (SCF standard gallery)
                if ($field['type'] === 'gallery') {
                    $field['return_format'] = 'array'; // SCF Gallery vrača array of attachment objects
                    $field['preview_size'] = isset($field_def['preview_size']) ? $field_def['preview_size'] : 'medium';
                    $field['insert'] = isset($field_def['insert']) ? $field_def['insert'] : 'append';
                    $field['library'] = isset($field_def['library']) ? $field_def['library'] : 'all';
                    $field['min'] = isset($field_def['min']) ? $field_def['min'] : '';
                    $field['max'] = isset($field_def['max']) ? $field_def['max'] : '';
                }
                
                $fields_array[] = $field;
                error_log('Atal ACF Sync: Prepared field ' . $field_def['name'] . ' (key: ' . $field_key . ', parent: ' . $group_key . ')');
            }
            
            if (empty($fields_array)) {
                error_log('Atal ACF Sync: No fields found for group ' . $group_data['title']);
                continue;
            }
            
            error_log('Atal ACF Sync: Prepared ' . count($fields_array) . ' fields for group ' . $group_data['title']);
            
            // Ustvari field group strukturo z location rules za oba post type
            // Polja morajo biti polni objekti, ne samo ključi
            $field_group = [
                'key' => $group_key,
                'title' => $group_data['title'],
                'fields' => $fields_array, // Polne field definicije
                'location' => $location_groups, // Vsak post type v svoji skupini (OR logika)
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
            ];
            
            error_log('Atal ACF Sync: Field group structure prepared: ' . json_encode([
                'key' => $field_group['key'],
                'title' => $field_group['title'],
                'fields_count' => count($field_group['fields']),
                'location_groups' => count($field_group['location']),
            ]));
            
            // Ustvari field group
            if (atal_create_acf_field_group($field_group)) {
                // Po ustvaritvi field group-a pridobi njegov ID in dodaj polja ločeno
                $created_group = acf_get_field_group($group_key);
                if ($created_group && isset($created_group['ID'])) {
                    $group_id = $created_group['ID'];
                    error_log('Atal ACF Sync: Field group created with ID: ' . $group_id);
                    
                    // Pridobi obstoječa polja v field group-u
                    $existing_fields = [];
                    if (function_exists('acf_get_fields')) {
                        $existing_fields = acf_get_fields($group_id);
                        if (!is_array($existing_fields)) {
                            $existing_fields = [];
                        }
                    }
                    
                    // Pridobi ključe polj iz API odgovora
                    $fields_from_api = [];
                    foreach ($fields_array as $field) {
                        if (isset($field['key'])) {
                            $fields_from_api[] = $field['key'];
                        }
                        if (isset($field['name'])) {
                            $fields_from_api[] = $field['name'];
                        }
                    }
                    
                    // Poišči polja, ki jih ni več v API odgovoru
                    // Pomembno: preveri, ali je polje res del tega field group-a (preko parent)
                    $fields_to_delete = [];
                    foreach ($existing_fields as $existing_field) {
                        // Preveri, ali je polje res del tega field group-a
                        $field_parent = isset($existing_field['parent']) ? $existing_field['parent'] : null;
                        $is_in_this_group = false;
                        
                        // Preveri, ali je parent ID ali key enak temu field group-u
                        if ($field_parent == $group_id || $field_parent == $group_key) {
                            $is_in_this_group = true;
                        }
                        
                        // Če ni parent informacije, preveri po ključu ali imenu
                        if (!$is_in_this_group && isset($existing_field['key'])) {
                            // Poskusi pridobiti polje in preveriti njegov parent
                            $field_check = acf_get_field($existing_field['key']);
                            if ($field_check && isset($field_check['parent'])) {
                                $is_in_this_group = ($field_check['parent'] == $group_id || $field_check['parent'] == $group_key);
                            }
                        }
                        
                        if (!$is_in_this_group) {
                            continue; // Preskoči polja, ki niso del tega field group-a
                        }
                        
                        $should_delete = false;
                        
                        // Preveri po ključu
                        if (isset($existing_field['key']) && !in_array($existing_field['key'], $fields_from_api)) {
                            $should_delete = true;
                        }
                        
                        // Preveri po imenu (za primer, ko se ključ spremeni)
                        if (isset($existing_field['name']) && !in_array($existing_field['name'], $fields_from_api)) {
                            $should_delete = true;
                        }
                        
                        if ($should_delete) {
                            $fields_to_delete[] = $existing_field;
                        }
                    }
                    
                    // Pobriši polja, ki jih ni več v API odgovoru
                    if (!empty($fields_to_delete)) {
                        error_log('Atal ACF Sync: Found ' . count($fields_to_delete) . ' fields to delete from field group ' . $group_data['title']);
                        foreach ($fields_to_delete as $field_to_delete) {
                            $field_key = isset($field_to_delete['key']) ? $field_to_delete['key'] : null;
                            $field_name = isset($field_to_delete['name']) ? $field_to_delete['name'] : 'unknown';
                            error_log('Atal ACF Sync: Field to delete: ' . $field_name . ' (key: ' . ($field_key ?: 'NONE') . ')');
                        }
                        
                        if (function_exists('acf_delete_field')) {
                            foreach ($fields_to_delete as $field_to_delete) {
                                $field_key = isset($field_to_delete['key']) ? $field_to_delete['key'] : null;
                                $field_name = isset($field_to_delete['name']) ? $field_to_delete['name'] : 'unknown';
                                
                                if ($field_key) {
                                    $delete_result = acf_delete_field($field_key);
                                    if ($delete_result) {
                                        error_log('Atal ACF Sync: Successfully deleted field ' . $field_name . ' (key: ' . $field_key . ') - no longer in API response');
                                    } else {
                                        error_log('Atal ACF Sync: Failed to delete field ' . $field_name . ' (key: ' . $field_key . ')');
                                    }
                                } else {
                                    error_log('Atal ACF Sync: Cannot delete field ' . $field_name . ' - no key found');
                                }
                            }
                        } else {
                            error_log('Atal ACF Sync: acf_delete_field function not available - cannot delete fields');
                        }
                    } else {
                        error_log('Atal ACF Sync: No fields to delete - all existing fields are in API response');
                    }
                    
                    // Dodaj vsako polje ločeno z acf_update_field
                    if (function_exists('acf_update_field')) {
                        foreach ($fields_array as $field) {
                            // Nastavi parent na ID field group-a
                            $field['parent'] = $group_id;
                            
                            // Preveri, ali polje že obstaja
                            $existing_field = false;
                            if (function_exists('acf_get_field') && isset($field['key'])) {
                                $existing_field = acf_get_field($field['key']);
                            }
                            
                            if ($existing_field && isset($existing_field['ID'])) {
                                $field['ID'] = $existing_field['ID'];
                                error_log('Atal ACF Sync: Updating existing field ' . $field['name'] . ' (ID: ' . $existing_field['ID'] . ') with parent ' . $group_id);
                            } else {
                                error_log('Atal ACF Sync: Creating new field ' . $field['name'] . ' with parent ' . $group_id);
                            }
                            
                            // Ustvari ali posodobi polje
                            $field_result = acf_update_field($field);
                            if ($field_result) {
                                error_log('Atal ACF Sync: Successfully ' . ($existing_field ? 'updated' : 'created') . ' field ' . $field['name']);
                            } else {
                                error_log('Atal ACF Sync: Failed to ' . ($existing_field ? 'update' : 'create') . ' field ' . $field['name']);
                            }
                        }
                        
                        // Po dodajanju vseh polj posodobi field group z vsemi polji
                        $updated_fields = [];
                        foreach ($fields_array as $field) {
                            $field['parent'] = $group_id;
                            $updated_fields[] = $field;
                        }
                        
                        $field_group['ID'] = $group_id;
                        $field_group['fields'] = $updated_fields;
                        
                        if (function_exists('acf_update_field_group')) {
                            $update_result = acf_update_field_group($field_group);
                            if ($update_result) {
                                error_log('Atal ACF Sync: Successfully updated field group with all fields');
                                
                                // Preveri, ali so polja pravilno dodana
                                $final_fields = acf_get_fields($group_id);
                                error_log('Atal ACF Sync: Field group now contains ' . count($final_fields) . ' fields');
                                foreach ($final_fields as $final_field) {
                                    error_log('Atal ACF Sync: Found field: ' . $final_field['name'] . ' (key: ' . $final_field['key'] . ')');
                                }
                            } else {
                                error_log('Atal ACF Sync: Failed to update field group with fields');
                            }
                        }
                    }
                } else {
                    error_log('Atal ACF Sync: ERROR - Could not retrieve field group ID after creation');
                }
                
                $created_count++;
                error_log('Atal ACF Sync: Successfully created/updated field group ' . $group_data['title'] . ' for post types: ' . implode(', ', $post_types) . ' with ' . count($fields_array) . ' fields');
            } else {
                error_log('Atal ACF Sync: Failed to create field group ' . $group_data['title']);
            }
        }
        
        return $created_count > 0;
    } catch (Exception $e) {
        error_log('Atal ACF Sync: Fatal error in atal_sync_acf_field_groups: ' . $e->getMessage());
        error_log('Atal ACF Sync: Stack trace: ' . $e->getTraceAsString());
        return false;
    }
}

/**
 * Avtomatsko sinhroniziraj ACF field groups ob uvozu
 */
add_action('atal_import_after_process', 'atal_sync_acf_field_groups');

/**
 * Ročna sinhronizacija preko admin strani
 */
add_action('admin_init', function() {
    if (isset($_GET['atal_sync_acf']) && $_GET['atal_sync_acf'] == '1' && current_user_can('manage_options')) {
        // Preveri nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'atal_sync_acf')) {
            wp_die('Security check failed');
        }
        
        // Preveri, ali je ACF nameščen
        if (!function_exists('acf_get_field_groups')) {
            wp_redirect(add_query_arg('atal_acf_sync_error', '2', admin_url('admin.php?page=atal-import')));
            exit;
        }
        
        try {
            $result = atal_sync_acf_field_groups();
            if ($result) {
                wp_redirect(add_query_arg('atal_acf_synced', '1', admin_url('admin.php?page=atal-import')));
            } else {
                wp_redirect(add_query_arg('atal_acf_sync_error', '1', admin_url('admin.php?page=atal-import')));
            }
        } catch (Exception $e) {
            error_log('Atal ACF Sync: Exception in admin_init: ' . $e->getMessage());
            wp_redirect(add_query_arg('atal_acf_sync_error', '3', admin_url('admin.php?page=atal-import')));
        }
        exit;
    }
});


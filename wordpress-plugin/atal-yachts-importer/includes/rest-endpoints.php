<?php
if (!defined('ABSPATH'))
    exit;

/**
 * REST endpoint za oddaljen trigger uvoza iz CMS-a
 * GET/POST /wp-json/atal-sync/v1/import?key=XXXXXXXX
 */
add_action('rest_api_init', function () {
    register_rest_route('atal-sync/v1', '/import', [
        'methods' => ['GET', 'POST'],
        'callback' => 'atal_sync_import_rest_callback',
        'permission_callback' => 'atal_sync_import_permission_check'
    ]);
});

/**
 * Preveri API ključ za uvoz
 */
function atal_sync_import_permission_check(WP_REST_Request $request)
{
    $api_key = $request->get_param('key');
    if (empty($api_key) || !defined('ATAL_IMPORT_API_KEY')) {
        return false;
    }
    return hash_equals(ATAL_IMPORT_API_KEY, $api_key);
}

/**
 * REST callback za uvoz
 */
function atal_sync_import_rest_callback(WP_REST_Request $request)
{
    // Check for "Push" payload (JSON body)
    $params = $request->get_json_params();

    if (!empty($params) && isset($params['type']) && isset($params['data'])) {
        // Push Mode
        if (!function_exists('atal_import_handle_push')) {
            return new WP_Error('server_error', 'Push import function not found', ['status' => 500]);
        }

        $result = atal_import_handle_push($params);
        return new WP_REST_Response($result, 200);
    }

    // Pull Mode (Default)
    if (!function_exists('atal_import_process')) {
        return new WP_Error('server_error', 'Import function not found', ['status' => 500]);
    }

    $result = atal_import_process();
    return new WP_REST_Response($result, 200);
}
<?php
/**
 * REST API Endpoint
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'atal_sync_register_rest_routes');

function atal_sync_register_rest_routes()
{
    register_rest_route('atal-sync/v1', '/import', [
        'methods' => 'POST',
        'callback' => 'atal_sync_rest_import',
        'permission_callback' => 'atal_sync_rest_permission',
    ]);

    register_rest_route('atal-sync/v1', '/status', [
        'methods' => 'GET',
        'callback' => 'atal_sync_rest_status',
        'permission_callback' => '__return_true',
    ]);
}

function atal_sync_rest_permission($request)
{
    $api_key = $request->get_header('X-API-Key');
    $stored_key = get_option('atal_sync_api_key');

    if (empty($stored_key)) {
        return new WP_Error('not_configured', 'API key not configured', ['status' => 500]);
    }

    if ($api_key !== $stored_key) {
        return new WP_Error('forbidden', 'Invalid API key', ['status' => 403]);
    }

    return true;
}

function atal_sync_rest_import($request)
{
    $type = $request->get_param('type');
    $data = $request->get_param('data');

    // Debug logging
    $all_params = $request->get_params();
    atal_log("REST API Import called. Type param: " . ($type ?? 'NULL'));
    atal_log("All Params: " . print_r($all_params, true));

    if (!empty($data)) {
        atal_log("Data keys: " . implode(', ', array_keys($data)));
    }

    if ($type === 'news' && !empty($data)) {
        atal_log("Calling atal_import_news()");
        $result = atal_import_news($data);
    } elseif (!empty($data) && ($type === 'new' || $type === 'used')) {
        // PUSH Mode for Yachts
        atal_log("Calling atal_import_single_yacht() (Push Mode) - type: $type");
        // Ensure type matches format expected by importer
        $data['type'] = $type;
        $success = atal_import_single_yacht($data);
        $result = [
            'imported' => $success ? 1 : 0,
            'errors' => $success ? [] : ['Failed to import yacht']
        ];
    } else {
        atal_log("Calling atal_import_yachts() (Pull Mode) - type: " . ($type ?? 'NULL'));
        // Default to yacht import (pull mode)
        $result = atal_import_yachts($type ?? 'new');
    }

    if (isset($result['error'])) {
        return new WP_Error('import_failed', $result['error'], ['status' => 500]);
    }

    return new WP_REST_Response([
        'success' => true,
        'imported' => $result['imported'],
        'errors' => $result['errors'] ?? [],
    ], 200);
}

function atal_sync_rest_status($request)
{
    $api_url = get_option('atal_sync_api_url');
    $api_key = get_option('atal_sync_api_key');

    return new WP_REST_Response([
        'configured' => !empty($api_url) && !empty($api_key),
        'api_url' => $api_url,
        'falang_active' => atal_is_falang_active(),
        'scf_active' => function_exists('SCF'),
    ], 200);
}

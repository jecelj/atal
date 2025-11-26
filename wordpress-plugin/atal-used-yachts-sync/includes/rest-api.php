<?php
/**
 * REST API Endpoints for Used Yachts Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    // Endpoint to receive yacht data from Master
    register_rest_route('atal-used-yachts/v1', '/sync', [
        'methods' => 'POST',
        'callback' => 'atal_used_yachts_sync_endpoint',
        'permission_callback' => 'atal_used_yachts_verify_request',
    ]);

    // Endpoint to receive field configuration
    register_rest_route('atal-used-yachts/v1', '/config', [
        'methods' => 'POST',
        'callback' => 'atal_used_yachts_config_endpoint',
        'permission_callback' => 'atal_used_yachts_verify_request',
    ]);
});

/**
 * Verify request from Master system
 */
function atal_used_yachts_verify_request($request)
{
    $api_key = get_option('atal_used_yachts_api_key');
    $provided_key = $request->get_header('X-API-Key');

    return $api_key && $provided_key === $api_key;
}

/**
 * Sync endpoint - receives yacht data
 */
function atal_used_yachts_sync_endpoint($request)
{
    $yachts = $request->get_json_params();

    if (empty($yachts)) {
        return new WP_Error('no_data', 'No yacht data provided', ['status' => 400]);
    }

    $results = [];
    foreach ($yachts as $yacht_data) {
        $result = atal_used_yachts_import_yacht($yacht_data);
        $results[] = $result;
    }

    return rest_ensure_response([
        'success' => true,
        'imported' => count(array_filter($results, fn($r) => $r['success'])),
        'failed' => count(array_filter($results, fn($r) => !$r['success'])),
        'results' => $results,
    ]);
}

/**
 * Config endpoint - receives field configuration
 */
function atal_used_yachts_config_endpoint($request)
{
    $config = $request->get_json_params();

    if (empty($config)) {
        return new WP_Error('no_config', 'No configuration provided', ['status' => 400]);
    }

    update_option('atal_used_yachts_field_config', $config);

    // Trigger ACF field regeneration
    do_action('acf/init');

    return rest_ensure_response([
        'success' => true,
        'message' => 'Configuration updated successfully',
    ]);
}

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

    register_rest_route('atal-sync/v1', '/push', [
        'methods' => 'POST',
        'callback' => 'atal_sync_rest_push',
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

function atal_sync_rest_push($request)
{
    $action = $request->get_param('action'); // 'update', 'delete', 'config'
    $items = $request->get_param('items');

    atal_log("REST API Push called. Action: " . ($action ?? 'NULL'));

    if ($action === 'config') {
        // Handle Field Definitions Push
        if (empty($items)) {
            return new WP_Error('missing_data', 'No configuration data provided', ['status' => 400]);
        }

        update_option('atal_sync_field_definitions', $items);
        atal_log("Updated Field Definitions via PUSH. Count: " . count($items));

        // Trigger re-registration if possible (runtime only)
        if (function_exists('atal_sync_register_scf_fields')) {
            atal_sync_register_scf_fields();
        }
        if (function_exists('atal_register_falang_fields')) {
            atal_register_falang_fields();
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Field definitions updated successfully',
            'count' => count($items)
        ], 200);

    } elseif ($action === 'delete') {
        // Handle Deletions
        $deletedCount = 0;
        $errors = [];

        foreach ($items as $item) {
            // $item = ['id' => 123, 'type' => 'new_yacht']
            // Find post by meta query (atal_sync_id)
            $posts = get_posts([
                'post_type' => ['new_yachts', 'used_yachts', 'news'],
                'meta_key' => 'atal_sync_id',
                'meta_value' => $item['id'], // Master ID
                'post_status' => 'any',
                'numberposts' => 1
            ]);

            if (!empty($posts)) {
                $post = $posts[0];
                $result = wp_delete_post($post->ID, true); // Force delete
                if ($result) {
                    $deletedCount++;
                    atal_log("Deleted Post ID {$post->ID} (Master ID: {$item['id']})");
                } else {
                    $errors[] = "Failed to delete Master ID {$item['id']}";
                }
            } else {
                // Already gone
                $deletedCount++;
            }
        }

        return new WP_REST_Response([
            'success' => empty($errors),
            'deleted' => $deletedCount,
            'errors' => $errors
        ], 200);

    } elseif ($action === 'update') {
        // Handle Updates (Batch)
        $importedCount = 0;
        $errors = [];

        foreach ($items as $payload) {
            $type = $payload['type'];
            // Route to appropriate importer
            if ($type === 'news') {
                $res = atal_import_news(['news' => [$payload]]);
                if (isset($res['imported']) && $res['imported'] > 0)
                    $importedCount++;
                else
                    $errors[] = "Failed to import News ID {$payload['id']}";
            } else {
                $success = atal_import_single_yacht($payload);
                if ($success)
                    $importedCount++;
                else
                    $errors[] = "Failed to import Yacht ID {$payload['id']}";
            }
        }

        return new WP_REST_Response([
            'success' => empty($errors),
            'imported' => $importedCount,
            'errors' => $errors
        ], 200);
    }

    return new WP_Error('invalid_action', 'Invalid action parameter', ['status' => 400]);
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

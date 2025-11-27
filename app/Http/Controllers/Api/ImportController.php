<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GaleonMigrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ImportController extends Controller
{
    protected $migrationService;

    public function __construct(GaleonMigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    /**
     * Import single yacht from galeonadriatic.com
     * 
     * POST /api/import/yacht
     */
    public function importYacht(Request $request)
    {
        Log::info('Import yacht request received', ['data' => $request->all()]);

        // Validate request
        $validator = Validator::make($request->all(), [
            'source_post_id' => 'required|integer',
            'name' => 'required|string',
            'slug' => 'required|string',
            'state' => 'required|in:new,used',
            'brand' => 'required|string',
            'model' => 'required|string',
            'fields' => 'required|array',
            'media' => 'required|array',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->migrationService->importYacht($request->all());

            if ($result['success']) {
                Log::info('Yacht imported successfully', ['yacht_id' => $result['yacht_id']]);
                return response()->json($result, 201);
            } else {
                Log::error('Import failed', ['error' => $result['error']]);
                return response()->json($result, 400);
            }

        } catch (\Exception $e) {
            Log::error('Import exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import Used Yacht field configuration
     * 
     * POST /api/import/used-yacht-fields
     */
    public function importUsedYachtFields(Request $request)
    {
        Log::info('Import Used Yacht fields request received', ['data' => $request->all()]);

        // Validate request
        $validator = Validator::make($request->all(), [
            'fields' => 'required|array',
            'fields.*.field_key' => 'required|string',
            'fields.*.field_type' => 'required|string',
            'fields.*.label' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Delete existing Used Yacht fields
            \App\Models\FormFieldConfiguration::forUsedYachts()->delete();

            // Create new fields
            $imported = 0;
            foreach ($request->input('fields') as $field) {
                \App\Models\FormFieldConfiguration::create([
                    'entity_type' => 'used_yacht',
                    'group' => $field['group'] ?? null,
                    'field_key' => $field['field_key'],
                    'field_type' => $field['field_type'],
                    'label' => $field['label'],
                    'is_required' => $field['is_required'] ?? false,
                    'is_multilingual' => $field['is_multilingual'] ?? false,
                    'order' => $field['order'] ?? 0,
                    'options' => $field['options'] ?? null,
                    'validation_rules' => $field['validation_rules'] ?? null,
                ]);
                $imported++;
            }

            Log::info('Used Yacht fields imported successfully', ['count' => $imported]);

            return response()->json([
                'success' => true,
                'message' => "Imported {$imported} field configurations",
                'count' => $imported,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Field import exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test endpoint to verify API is working
     * 
     * GET /api/import/test
     */
    public function test()
    {
        return response()->json([
            'success' => true,
            'message' => 'Galeon import API is working',
            'timestamp' => now()->toISOString(),
        ]);
    }
}

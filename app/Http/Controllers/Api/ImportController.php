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

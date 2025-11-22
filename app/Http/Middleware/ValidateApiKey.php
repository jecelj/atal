<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Settings\ApiSettings;

class ValidateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = app(ApiSettings::class);
            $apiKey = $settings->sync_api_key;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'API key not configured',
                'message' => 'Please configure API key in Filament admin panel.',
            ], 500);
        }

        // Check if API key is configured
        if (empty($apiKey)) {
            return response()->json([
                'error' => 'API key not configured',
                'message' => 'Please set API key in Configuration > API Settings.',
            ], 500);
        }

        // Get API key from request (supports both query parameter and header)
        $requestKey = $request->query('api_key') ?? $request->bearerToken();

        // Validate API key
        if ($requestKey !== $apiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key',
            ], 401);
        }

        return $next($request);
    }
}

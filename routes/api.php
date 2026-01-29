<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// WordPress Sync API (protected with API key)
Route::prefix('sync')->middleware('validate.api.key')->group(function () {
    Route::get('yachts', [\App\Http\Controllers\Api\SyncController::class, 'yachts']);
    Route::get('brands', [\App\Http\Controllers\Api\SyncController::class, 'brands']);
    Route::get('models', [\App\Http\Controllers\Api\SyncController::class, 'models']);
    Route::get('fields', [\App\Http\Controllers\Api\SyncController::class, 'fields']);
});

// Galeon Migration Import API (protected with API key)
Route::prefix('import')->middleware('validate.api.key')->group(function () {
    Route::get('test', [\App\Http\Controllers\Api\ImportController::class, 'test']);
    Route::post('yacht', [\App\Http\Controllers\Api\ImportController::class, 'importYacht']);
    Route::post('used-yacht-fields', [\App\Http\Controllers\Api\ImportController::class, 'importUsedYachtFields']);
});

// Used Yacht Sync API
Route::prefix('used-yachts')->group(function () {
    Route::post('sync/{site}', [\App\Http\Controllers\Api\UsedYachtSyncController::class, 'syncToSite']);
    Route::post('sync/{site}/yacht/{yacht}', [\App\Http\Controllers\Api\UsedYachtSyncController::class, 'syncSingleYacht']);
    Route::post('sync-all', [\App\Http\Controllers\Api\UsedYachtSyncController::class, 'syncAllSites']);
});

// Charter Yacht Sync API
Route::prefix('charter-yachts')->group(function () {
    Route::post('sync/{site}', [\App\Http\Controllers\Api\CharterYachtSyncController::class, 'syncToSite']);
    Route::post('sync/{site}/yacht/{yacht}', [\App\Http\Controllers\Api\CharterYachtSyncController::class, 'syncSingleYacht']);
    Route::post('sync-all', [\App\Http\Controllers\Api\CharterYachtSyncController::class, 'syncAllSites']);
});

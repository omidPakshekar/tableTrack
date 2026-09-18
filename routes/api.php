<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PrintJobController;
use App\Http\Controllers\PrintStreamController;
use App\Http\Middleware\DesktopUniqueKeyMiddleware;
use App\Http\Controllers\PosApiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OfflineController;

// SSE: same token as branch unique_hash (X-TABLETRACK-KEY). EventSource cannot send headers.
Route::get('/print-stream/{token}', [PrintStreamController::class, 'stream']);

// called by Electron / Tauri every X seconds (smart polling fallback) or for REST operations
Route::middleware(DesktopUniqueKeyMiddleware::class)->group(function () {
    Route::get('/test-connection', [PrintJobController::class, 'testConnection']);

    //Multiple job pull
    Route::get('/print-jobs/pull-multiple', [PrintJobController::class, 'pullMultiple']);

    Route::get('/printer-details', [PrintJobController::class, 'printerDetails']);

    // mark a job done/failed
    Route::patch('/print-jobs/{printJob}', [PrintJobController::class, 'update']);

    Route::post('/print-jobs/{printJob}/printed', [PrintStreamController::class, 'markPrinted']);
});


Route::prefix('partner/orders')->group(function () {
    Route::get('/{status?}', [PosApiController::class, 'getOrders']);
});

Route::post('application-integration/partner/auth/validate-domain', [HomeController::class, 'validatePartnerDomain']);
Route::get('/bootstrap', [OfflineController::class, 'bootstrap']);
Route::post('/bootstrap/offline/orders', [OfflineController::class, 'createOrder']);

<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController;
use Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboMigrationController;
use Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboWebhookController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/accounting/quickbooks-online-migration')->group(function (): void {
    Route::get('/', [QboMigrationController::class, 'index']);
    Route::post('/', [QboMigrationController::class, 'store']);
    Route::get('/{run}', [QboMigrationController::class, 'show']);
    Route::post('/{run}/reconcile', [QboMigrationController::class, 'reconcile']);
});

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('qbo')->group(function (): void {
        Route::get('/connect', [QboController::class, 'connect']);
        Route::get('/callback', [QboController::class, 'callback']);
        Route::get('/connections', [QboController::class, 'listConnections']);
        Route::post('/connections/{connection}/sync', [QboController::class, 'sync'])->middleware('throttle:10,1');
        Route::delete('/connections/{connection}', [QboController::class, 'removeConnection']);
    });

    Route::post('/webhooks/qbo', [QboWebhookController::class, 'handle']);
});

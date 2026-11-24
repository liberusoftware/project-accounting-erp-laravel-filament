<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboMigrationController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/accounting/quickbooks-online-migration')->group(function (): void {
    Route::get('/', [QboMigrationController::class, 'index']);
    Route::post('/', [QboMigrationController::class, 'store']);
    Route::get('/{run}', [QboMigrationController::class, 'show']);
    Route::post('/{run}/reconcile', [QboMigrationController::class, 'reconcile']);
});

\Illuminate\Support\Facades\Route::middleware('api')->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('qbo')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('/connect', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController::class, 'connect']);
        \Illuminate\Support\Facades\Route::get('/callback', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController::class, 'callback']);
        \Illuminate\Support\Facades\Route::get('/connections', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController::class, 'listConnections']);
        \Illuminate\Support\Facades\Route::post('/connections/{connection}/sync', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController::class, 'sync'])->middleware('throttle:10,1');
        \Illuminate\Support\Facades\Route::delete('/connections/{connection}', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboController::class, 'removeConnection']);
    });

    \Illuminate\Support\Facades\Route::post('/webhooks/qbo', [\Liberu\Accounting\QuickBooksOnlineMigrationApi\Http\Controllers\QboWebhookController::class, 'handle']);
});

<?php

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController;
use Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroMigrationController;
use Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroWebhookController;

Route::prefix('api/v1/accounting/xero-migration')->middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/connections', [XeroMigrationController::class, 'connections'])->middleware('ability:accounting.xero-migration.read');
    Route::post('/connections', [XeroMigrationController::class, 'connect'])->middleware('ability:accounting.xero-migration.write');
    Route::post('/connections/{connection}/records', [XeroMigrationController::class, 'record'])->middleware('ability:accounting.xero-migration.write');
});

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('xero')->group(function (): void {
        Route::get('/connect', [XeroController::class, 'connect']);
        Route::get('/callback', [XeroController::class, 'callback']);
        Route::get('/connections', [XeroController::class, 'listConnections']);
        Route::post('/connections/{connection}/sync', [XeroController::class, 'sync'])->middleware('throttle:10,1');
        Route::delete('/connections/{connection}', [XeroController::class, 'removeConnection']);
    });

    Route::post('/webhooks/xero', [XeroWebhookController::class, 'handle']);
});

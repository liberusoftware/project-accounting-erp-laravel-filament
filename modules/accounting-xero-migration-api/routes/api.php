<?php

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroMigrationController;

Route::prefix('api/v1/accounting/xero-migration')->middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/connections', [XeroMigrationController::class, 'connections'])->middleware('ability:accounting.xero-migration.read');
    Route::post('/connections', [XeroMigrationController::class, 'connect'])->middleware('ability:accounting.xero-migration.write');
    Route::post('/connections/{connection}/records', [XeroMigrationController::class, 'record'])->middleware('ability:accounting.xero-migration.write');
});

\Illuminate\Support\Facades\Route::middleware('api')->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('xero')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('/connect', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController::class, 'connect']);
        \Illuminate\Support\Facades\Route::get('/callback', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController::class, 'callback']);
        \Illuminate\Support\Facades\Route::get('/connections', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController::class, 'listConnections']);
        \Illuminate\Support\Facades\Route::post('/connections/{connection}/sync', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController::class, 'sync'])->middleware('throttle:10,1');
        \Illuminate\Support\Facades\Route::delete('/connections/{connection}', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroController::class, 'removeConnection']);
    });

    \Illuminate\Support\Facades\Route::post('/webhooks/xero', [\Liberu\Accounting\XeroMigrationApi\Http\Controllers\XeroWebhookController::class, 'handle']);
});

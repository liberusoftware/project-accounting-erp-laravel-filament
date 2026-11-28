<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController;
use Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageMigrationController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/accounting/sage-accounting-migration')->group(function (): void {
    Route::get('/', [SageMigrationController::class, 'index']);
    Route::post('/', [SageMigrationController::class, 'store']);
    Route::get('/{run}', [SageMigrationController::class, 'show']);
    Route::post('/{run}/reconcile', [SageMigrationController::class, 'reconcile']);
});

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('sage')->group(function (): void {
        Route::get('/connect', [SageController::class, 'connect']);
        Route::get('/callback', [SageController::class, 'callback']);
        Route::get('/connections', [SageController::class, 'listConnections']);
        Route::post('/connections/{connection}/sync', [SageController::class, 'sync'])->middleware('throttle:10,1');
        Route::delete('/connections/{connection}', [SageController::class, 'removeConnection']);
    });
});

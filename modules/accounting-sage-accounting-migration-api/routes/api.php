<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageMigrationController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/accounting/sage-accounting-migration')->group(function (): void {
    Route::get('/', [SageMigrationController::class, 'index']);
    Route::post('/', [SageMigrationController::class, 'store']);
    Route::get('/{run}', [SageMigrationController::class, 'show']);
    Route::post('/{run}/reconcile', [SageMigrationController::class, 'reconcile']);
});

\Illuminate\Support\Facades\Route::middleware('api')->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('sage')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('/connect', [\Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController::class, 'connect']);
        \Illuminate\Support\Facades\Route::get('/callback', [\Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController::class, 'callback']);
        \Illuminate\Support\Facades\Route::get('/connections', [\Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController::class, 'listConnections']);
        \Illuminate\Support\Facades\Route::post('/connections/{connection}/sync', [\Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController::class, 'sync'])->middleware('throttle:10,1');
        \Illuminate\Support\Facades\Route::delete('/connections/{connection}', [\Liberu\Accounting\SageAccountingMigrationApi\Http\Controllers\SageController::class, 'removeConnection']);
    });
});

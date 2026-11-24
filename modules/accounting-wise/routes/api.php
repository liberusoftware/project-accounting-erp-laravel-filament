<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\Wise\Http\Controllers\WiseController;
use Liberu\Accounting\Wise\Http\Controllers\WiseWebhookController;

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('wise')->group(function (): void {
        Route::get('/authorize', [WiseController::class, 'redirectToWise']);
        Route::post('/callback', [WiseController::class, 'handleCallback']);
        Route::get('/connections', [WiseController::class, 'listConnections']);
        Route::get('/connections/{connection}/accounts', [WiseController::class, 'getAccounts'])->middleware('throttle:30,1');
        Route::post('/connections/{connection}/sync', [WiseController::class, 'syncTransactions'])->middleware('throttle:10,1');
        Route::delete('/connections/{connection}', [WiseController::class, 'removeConnection']);
    });

    Route::post('/webhooks/wise', [WiseWebhookController::class, 'handle']);
});

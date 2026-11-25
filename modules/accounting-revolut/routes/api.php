<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\Revolut\Http\Controllers\RevolutController;
use Liberu\Accounting\Revolut\Http\Controllers\RevolutWebhookController;

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('revolut')->group(function (): void {
        Route::get('/authorize', [RevolutController::class, 'redirectToRevolut']);
        Route::post('/callback', [RevolutController::class, 'handleCallback']);
        Route::get('/connections', [RevolutController::class, 'listConnections']);
        Route::get('/connections/{connection}/accounts', [RevolutController::class, 'getAccounts'])->middleware('throttle:30,1');
        Route::post('/connections/{connection}/sync', [RevolutController::class, 'syncTransactions'])->middleware('throttle:10,1');
        Route::post('/connections/{connection}/pay', [RevolutController::class, 'sendPayment'])->middleware(['throttle:30,1', 'ability:payments:write']);
        Route::post('/connections/{connection}/bulk-pay', [RevolutController::class, 'sendBulkPayment'])->middleware(['throttle:10,1', 'ability:payments:write']);
        Route::delete('/connections/{connection}', [RevolutController::class, 'removeConnection']);
    });

    Route::post('/webhooks/revolut', [RevolutWebhookController::class, 'handle']);
});

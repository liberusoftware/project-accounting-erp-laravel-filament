<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\Plaid\Http\Controllers\PlaidController;
use Liberu\Accounting\Plaid\Http\Controllers\PlaidWebhookController;

Route::middleware('api')->prefix('api')->group(function (): void {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('plaid')->group(function (): void {
        Route::post('/create-link-token', [PlaidController::class, 'createLinkToken']);
        Route::post('/store-connection', [PlaidController::class, 'storeConnection']);
        Route::get('/connections', [PlaidController::class, 'listConnections']);
        Route::post('/connections/{connection}/sync', [PlaidController::class, 'syncTransactions'])->middleware('throttle:10,1');
        Route::get('/connections/{connection}/balances', [PlaidController::class, 'getBalances'])->middleware('throttle:30,1');
        Route::delete('/connections/{connection}', [PlaidController::class, 'removeConnection']);
    });

    Route::get('/plaid/oauth-redirect', [PlaidController::class, 'handleOAuthRedirect']);
    Route::post('/webhooks/plaid', [PlaidWebhookController::class, 'handle']);
});

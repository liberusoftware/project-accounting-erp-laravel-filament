<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\AccountController;
use Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController;

Route::prefix('api/v1/accounting/chart-of-accounts')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->middleware('ability:accounting.chart.read');
    Route::get('accounts/tree', [AccountController::class, 'tree'])->middleware('ability:accounting.chart.read');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->middleware('ability:accounting.chart.read');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('ability:accounting.chart.write');
    Route::match(['put', 'patch'], 'accounts/{account}', [AccountController::class, 'update'])->middleware('ability:accounting.chart.write');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('ability:accounting.chart.write');
});

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    Route::apiResource('chart-of-accounts', ChartOfAccountController::class);

    Route::prefix('v1')->group(function (): void {
        Route::get('chart-of-accounts', [ChartOfAccountController::class, 'index'])->middleware('ability:chart-of-accounts:read');
        Route::get('chart-of-accounts/{chart_of_account}', [ChartOfAccountController::class, 'show'])->middleware('ability:chart-of-accounts:read');
        Route::post('chart-of-accounts', [ChartOfAccountController::class, 'store'])->middleware('ability:chart-of-accounts:write');
        Route::put('chart-of-accounts/{chart_of_account}', [ChartOfAccountController::class, 'update'])->middleware('ability:chart-of-accounts:write');
        Route::delete('chart-of-accounts/{chart_of_account}', [ChartOfAccountController::class, 'destroy'])->middleware('ability:chart-of-accounts:write');
    });
});

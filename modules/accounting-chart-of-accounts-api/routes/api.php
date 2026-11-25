<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\AccountController;

Route::prefix('api/v1/accounting/chart-of-accounts')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->middleware('ability:accounting.chart.read');
    Route::get('accounts/tree', [AccountController::class, 'tree'])->middleware('ability:accounting.chart.read');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->middleware('ability:accounting.chart.read');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('ability:accounting.chart.write');
    Route::match(['put', 'patch'], 'accounts/{account}', [AccountController::class, 'update'])->middleware('ability:accounting.chart.write');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('ability:accounting.chart.write');
});

\Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::apiResource('chart-of-accounts', \Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class);

    \Illuminate\Support\Facades\Route::prefix('v1')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('chart-of-accounts', [\Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class, 'index'])->middleware('ability:chart-of-accounts:read');
        \Illuminate\Support\Facades\Route::get('chart-of-accounts/{chart_of_account}', [\Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class, 'show'])->middleware('ability:chart-of-accounts:read');
        \Illuminate\Support\Facades\Route::post('chart-of-accounts', [\Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class, 'store'])->middleware('ability:chart-of-accounts:write');
        \Illuminate\Support\Facades\Route::put('chart-of-accounts/{chart_of_account}', [\Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class, 'update'])->middleware('ability:chart-of-accounts:write');
        \Illuminate\Support\Facades\Route::delete('chart-of-accounts/{chart_of_account}', [\Liberu\Accounting\ChartOfAccountsApi\Http\Controllers\ChartOfAccountController::class, 'destroy'])->middleware('ability:chart-of-accounts:write');
    });
});

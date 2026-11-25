<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimatesController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/accounting/estimates-and-quotes')->group(function (): void {
    Route::middleware('ability:accounting.estimates-and-quotes.read')->group(function (): void {
        Route::get('/', [EstimatesController::class, 'index']);
        Route::get('/{estimate}', [EstimatesController::class, 'show']);
    });
    Route::middleware('ability:accounting.estimates-and-quotes.write')->group(function (): void {
        Route::post('/', [EstimatesController::class, 'store']);
        Route::post('/{estimate}/items', [EstimatesController::class, 'item']);
        Route::post('/{estimate}/send', [EstimatesController::class, 'send']);
        Route::post('/{estimate}/decide', [EstimatesController::class, 'decide']);
        Route::post('/{estimate}/expire', [EstimatesController::class, 'expire']);
        Route::post('/{estimate}/convert', [EstimatesController::class, 'convert']);
    });
});

\Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::apiResource('estimates', \Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class);

    \Illuminate\Support\Facades\Route::prefix('v1')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('estimates', [\Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class, 'index'])->middleware('ability:estimates:read');
        \Illuminate\Support\Facades\Route::get('estimates/{estimate}', [\Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class, 'show'])->middleware('ability:estimates:read');
        \Illuminate\Support\Facades\Route::post('estimates', [\Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class, 'store'])->middleware('ability:estimates:write');
        \Illuminate\Support\Facades\Route::put('estimates/{estimate}', [\Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class, 'update'])->middleware('ability:estimates:write');
        \Illuminate\Support\Facades\Route::delete('estimates/{estimate}', [\Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimateController::class, 'destroy'])->middleware('ability:estimates:write');
    });
});

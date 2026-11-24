<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\SupplierBillsApi\Http\Controllers\SupplierBillController;

Route::prefix('api/v1/accounting/supplier-bills')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::middleware('ability:accounting.supplier-bills.read')->group(function (): void {
        Route::get('/', [SupplierBillController::class, 'index']);
        Route::get('/duplicates', [SupplierBillController::class, 'duplicates']);
        Route::get('/aging', [SupplierBillController::class, 'aging']);
        Route::get('/{supplierBill}', [SupplierBillController::class, 'show']);
    });
    Route::middleware('ability:accounting.supplier-bills.write')->group(function (): void {
        Route::post('/', [SupplierBillController::class, 'store']);
        Route::patch('/{supplierBill}', [SupplierBillController::class, 'update']);
        Route::post('/{supplierBill}/approve', [SupplierBillController::class, 'approve']);
        Route::post('/{supplierBill}/post', [SupplierBillController::class, 'post']);
        Route::post('/{supplierBill}/reject', [SupplierBillController::class, 'reject']);
        Route::post('/{supplierBill}/void', [SupplierBillController::class, 'void']);
        Route::post('/{supplierBill}/credits', [SupplierBillController::class, 'credit']);
        Route::post('/{supplierBill}/matches', [SupplierBillController::class, 'match']);
        Route::post('/{supplierBill}/documents', [SupplierBillController::class, 'document']);
    });
});

\Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::apiResource('bills', \Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class);

    \Illuminate\Support\Facades\Route::prefix('v1')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('bills', [\Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class, 'index'])->middleware('ability:bills:read');
        \Illuminate\Support\Facades\Route::get('bills/{bill}', [\Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class, 'show'])->middleware('ability:bills:read');
        \Illuminate\Support\Facades\Route::post('bills', [\Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class, 'store'])->middleware('ability:bills:write');
        \Illuminate\Support\Facades\Route::put('bills/{bill}', [\Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class, 'update'])->middleware('ability:bills:write');
        \Illuminate\Support\Facades\Route::delete('bills/{bill}', [\Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController::class, 'destroy'])->middleware('ability:bills:write');
    });
});

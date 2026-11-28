<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\SupplierBillsApi\Http\Controllers\BillController;
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

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    Route::apiResource('bills', BillController::class);

    Route::prefix('v1')->group(function (): void {
        Route::get('bills', [BillController::class, 'index'])->middleware('ability:bills:read');
        Route::get('bills/{bill}', [BillController::class, 'show'])->middleware('ability:bills:read');
        Route::post('bills', [BillController::class, 'store'])->middleware('ability:bills:write');
        Route::put('bills/{bill}', [BillController::class, 'update'])->middleware('ability:bills:write');
        Route::delete('bills/{bill}', [BillController::class, 'destroy'])->middleware('ability:bills:write');
    });
});

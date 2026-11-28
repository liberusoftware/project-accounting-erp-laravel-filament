<?php

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController;
use Liberu\Accounting\SalesInvoicingApi\Http\Controllers\SalesInvoicingController;

Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('api/v1/accounting/sales-invoicing')->group(function (): void {
    Route::get('/', [SalesInvoicingController::class, 'index'])->middleware('ability:accounting.invoices.read');
    Route::post('/', [SalesInvoicingController::class, 'store'])->middleware('ability:accounting.invoices.write');
    Route::get('/{invoice}', [SalesInvoicingController::class, 'show'])->middleware('ability:accounting.invoices.read');
    Route::post('/{invoice}/approve', [SalesInvoicingController::class, 'approve'])->middleware('ability:accounting.invoices.write');
    Route::post('/{invoice}/finalize', [SalesInvoicingController::class, 'finalize'])->middleware('ability:accounting.invoices.write');
    Route::post('/{invoice}/deposit', [SalesInvoicingController::class, 'deposit'])->middleware('ability:accounting.invoices.write');
    Route::post('/{invoice}/deliver', [SalesInvoicingController::class, 'deliver'])->middleware('ability:accounting.invoices.write');
    Route::delete('/{invoice}', [SalesInvoicingController::class, 'destroy'])->middleware('ability:accounting.invoices.write');
});

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    Route::apiResource('invoices', InvoiceController::class);

    Route::prefix('v1')->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('ability:invoices:read');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('ability:invoices:read');
        Route::post('invoices', [InvoiceController::class, 'store'])->middleware('ability:invoices:write');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('ability:invoices:write');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('ability:invoices:write');
    });
});

<?php

use Illuminate\Support\Facades\Route;
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

\Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::apiResource('invoices', \Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class);

    \Illuminate\Support\Facades\Route::prefix('v1')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('invoices', [\Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class, 'index'])->middleware('ability:invoices:read');
        \Illuminate\Support\Facades\Route::get('invoices/{invoice}', [\Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class, 'show'])->middleware('ability:invoices:read');
        \Illuminate\Support\Facades\Route::post('invoices', [\Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class, 'store'])->middleware('ability:invoices:write');
        \Illuminate\Support\Facades\Route::put('invoices/{invoice}', [\Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class, 'update'])->middleware('ability:invoices:write');
        \Illuminate\Support\Facades\Route::delete('invoices/{invoice}', [\Liberu\Accounting\SalesInvoicingApi\Http\Controllers\InvoiceController::class, 'destroy'])->middleware('ability:invoices:write');
    });
});

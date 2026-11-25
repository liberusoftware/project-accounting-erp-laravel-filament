<?php

use Illuminate\Support\Facades\Route;
use Liberu\Accounting\GeneralLedgerApi\Http\Controllers\GeneralLedgerController;

Route::middleware('auth:sanctum')->prefix('api/v1/accounting/general-ledger')->group(function (): void {
    Route::middleware('ability:accounting.general-ledger.read')->group(function (): void {
        Route::get('/', [GeneralLedgerController::class, 'index']);
        Route::get('/balances', [GeneralLedgerController::class, 'balances']);
        Route::get('/{journal}', [GeneralLedgerController::class, 'show']);
    });
    Route::middleware('ability:accounting.general-ledger.write')->group(function (): void {
        Route::post('/', [GeneralLedgerController::class, 'store']);
        Route::post('/recurring', [GeneralLedgerController::class, 'saveRecurring']);
        Route::post('/recurring/{recurring}/generate', [GeneralLedgerController::class, 'generateRecurring']);
        foreach (['corrections', 'allocations', 'accruals', 'prepayments'] as $type) {
            Route::post('/'.$type, [GeneralLedgerController::class, 'storeTyped'])->defaults('type', $type);
        }Route::post('/{journal}/post', [GeneralLedgerController::class, 'post']);
        Route::post('/{journal}/reverse', [GeneralLedgerController::class, 'reverse']);
        Route::delete('/{journal}', [GeneralLedgerController::class, 'destroy']);
    });
});

\Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->prefix('api')->group(function (): void {
    \Illuminate\Support\Facades\Route::apiResource('journal-entries', \Liberu\Accounting\GeneralLedgerApi\Http\Controllers\JournalEntryController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    \Illuminate\Support\Facades\Route::get('/general-ledger/trial-balance', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\ErpGeneralLedgerController::class, 'trialBalance']);
    \Illuminate\Support\Facades\Route::get('/general-ledger/balances', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\ErpGeneralLedgerController::class, 'balances']);

    \Illuminate\Support\Facades\Route::prefix('v1')->group(function (): void {
        \Illuminate\Support\Facades\Route::get('journal-entries', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\JournalEntryController::class, 'index'])->middleware('ability:journal-entries:read');
        \Illuminate\Support\Facades\Route::get('journal-entries/{journal_entry}', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\JournalEntryController::class, 'show'])->middleware('ability:journal-entries:read');
        \Illuminate\Support\Facades\Route::post('journal-entries', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\JournalEntryController::class, 'store'])->middleware('ability:journal-entries:write');
        \Illuminate\Support\Facades\Route::delete('journal-entries/{journal_entry}', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\JournalEntryController::class, 'destroy'])->middleware('ability:journal-entries:write');
        \Illuminate\Support\Facades\Route::get('general-ledger/trial-balance', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\ErpGeneralLedgerController::class, 'trialBalance'])->middleware('ability:general-ledger:read');
        \Illuminate\Support\Facades\Route::get('general-ledger/balances', [\Liberu\Accounting\GeneralLedgerApi\Http\Controllers\ErpGeneralLedgerController::class, 'balances'])->middleware('ability:general-ledger:read');
    });
});

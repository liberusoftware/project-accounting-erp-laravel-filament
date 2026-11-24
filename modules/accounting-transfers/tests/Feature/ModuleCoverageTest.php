<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Transfers\Actions\CompleteTransfer;
use Liberu\Accounting\Transfers\Actions\CreateTransfer;
use Liberu\Accounting\Transfers\Actions\ReconcileTransfer;
use Liberu\Accounting\Transfers\Enums\TransferStatus;
use Liberu\Accounting\Transfers\Exceptions\InvalidTransfer;
use Liberu\Accounting\Transfers\Models\Transfer;
use Liberu\Accounting\Transfers\Models\TransferReconciliation;
use Liberu\Accounting\Transfers\TransfersServiceProvider;

it('registers transfers service provider and creates tables', function (): void {
    expect(app()->getProvider(TransfersServiceProvider::class))
        ->toBeInstanceOf(TransfersServiceProvider::class);
    expect(Schema::hasTable('accounting_transfers'))->toBeTrue();
    expect(Schema::hasTable('accounting_transfer_reconciliations'))->toBeTrue();
});

it('exposes transfer status enum cases', function (): void {
    expect(array_map(static fn (TransferStatus $s): string => $s->value, TransferStatus::cases()))
        ->toBe(['draft', 'in_transit', 'completed', 'reconciled', 'failed', 'cancelled']);
});

it('creates transfers, calculates destination amount, and validates inputs', function (): void {
    $create = app(CreateTransfer::class);

    $fxTransfer = $create->handle([
        'team_id' => 1,
        'source_account_ref' => 'ACC-USD',
        'destination_account_ref' => 'ACC-EUR',
        'source_currency' => 'USD',
        'destination_currency' => 'EUR',
        'source_amount' => 100,
        'exchange_rate' => 0.9125,
        'fee_amount' => 2.5,
        'reference' => 'TRF-100',
        'metadata' => ['channel' => 'wire'],
    ]);

    expect($fxTransfer)->toBeInstanceOf(Transfer::class)
        ->and($fxTransfer->status)->toBe(TransferStatus::InTransit)
        ->and($fxTransfer->destination_amount)->toBe('91.250000')
        ->and($fxTransfer->exchange_rate)->toBe('0.9125000000')
        ->and($fxTransfer->fee_amount)->toBe('2.500000')
        ->and($fxTransfer->metadata)->toBe(['channel' => 'wire']);

    $explicitTransfer = $create->handle([
        'team_id' => 1,
        'source_account_ref' => 'ACC-USD',
        'destination_account_ref' => 'ACC-GBP',
        'source_currency' => 'USD',
        'destination_currency' => 'GBP',
        'source_amount' => 200,
        'destination_amount' => 155,
        'exchange_rate' => 0.8,
        'status' => TransferStatus::Draft,
    ]);

    expect($explicitTransfer->status)->toBe(TransferStatus::Draft)
        ->and($explicitTransfer->destination_amount)->toBe('155.000000');

    $invalidPayloads = [
        ['team_id' => 1, 'destination_account_ref' => 'ACC-2', 'source_currency' => 'USD', 'destination_currency' => 'USD', 'source_amount' => 10, 'exchange_rate' => 1],
        ['team_id' => 1, 'source_account_ref' => 'ACC-1', 'source_currency' => 'USD', 'destination_currency' => 'USD', 'source_amount' => 10, 'exchange_rate' => 1],
        ['team_id' => 1, 'source_account_ref' => 'ACC-1', 'destination_account_ref' => 'ACC-1', 'source_currency' => 'USD', 'destination_currency' => 'USD', 'source_amount' => 10, 'exchange_rate' => 1],
        ['team_id' => 1, 'source_account_ref' => 'ACC-1', 'destination_account_ref' => 'ACC-2', 'source_currency' => 'USD', 'destination_currency' => 'USD', 'source_amount' => 0, 'exchange_rate' => 1],
        ['team_id' => 1, 'source_account_ref' => 'ACC-1', 'destination_account_ref' => 'ACC-2', 'source_currency' => 'USD', 'destination_currency' => 'USD', 'source_amount' => 10, 'exchange_rate' => 0],
    ];

    foreach ($invalidPayloads as $payload) {
        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidTransfer::class, 'Transfer accounts must differ and amounts/rates must be positive.');
    }
});

it('completes in-transit transfers and rejects non-in-transit transfers', function (): void {
    $create = app(CreateTransfer::class);
    $complete = app(CompleteTransfer::class);

    $inTransit = $create->handle([
        'team_id' => 2,
        'source_account_ref' => 'SRC-1',
        'destination_account_ref' => 'DST-1',
        'source_currency' => 'USD',
        'destination_currency' => 'USD',
        'source_amount' => 50,
        'exchange_rate' => 1,
    ]);

    $completed = $complete->handle($inTransit);
    expect($completed->status)->toBe(TransferStatus::Completed);

    expect(fn () => $complete->handle($completed))
        ->toThrow(InvalidTransfer::class, 'Only in-transit transfers can be completed.');

    $draft = $create->handle([
        'team_id' => 2,
        'source_account_ref' => 'SRC-1',
        'destination_account_ref' => 'DST-2',
        'source_currency' => 'USD',
        'destination_currency' => 'USD',
        'source_amount' => 50,
        'exchange_rate' => 1,
        'status' => TransferStatus::Draft,
    ]);

    expect(fn () => $complete->handle($draft))
        ->toThrow(InvalidTransfer::class, 'Only in-transit transfers can be completed.');
});

it('reconciles a transfer and exposes model relationships', function (): void {
    $transfer = app(CreateTransfer::class)->handle([
        'team_id' => 3,
        'source_account_ref' => 'BANK-A',
        'destination_account_ref' => 'BANK-B',
        'source_currency' => 'EUR',
        'destination_currency' => 'EUR',
        'source_amount' => 300,
        'exchange_rate' => 1,
    ]);

    $completed = app(CompleteTransfer::class)->handle($transfer);

    $reconciliation = app(ReconcileTransfer::class)->handle($completed, [
        'external_ref' => 'STMT-LINE-99',
        'amount' => 300,
        'reconciled_on' => '2026-06-01',
        'metadata' => ['matched_by' => 'auto'],
    ]);

    expect($reconciliation)->toBeInstanceOf(TransferReconciliation::class)
        ->and($reconciliation->team_id)->toBe(3)
        ->and($reconciliation->status)->toBe('matched')
        ->and($reconciliation->amount)->toBe('300.000000')
        ->and($reconciliation->reconciled_on->toDateString())->toBe('2026-06-01')
        ->and($reconciliation->metadata)->toBe(['matched_by' => 'auto'])
        ->and($reconciliation->transfer->is($transfer))->toBeTrue()
        ->and($transfer->refresh()->status)->toBe(TransferStatus::Reconciled)
        ->and($transfer->reconciliations()->count())->toBe(1);
});

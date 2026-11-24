<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ReceiptManagement\Actions\IngestReceipt;
use Liberu\Accounting\ReceiptManagement\Actions\MatchReceipt;
use Liberu\Accounting\ReceiptManagement\Actions\RequestMissingReceipt;
use Liberu\Accounting\ReceiptManagement\Enums\ReceiptStatus;
use Liberu\Accounting\ReceiptManagement\Exceptions\InvalidReceipt;
use Liberu\Accounting\ReceiptManagement\Models\MissingReceiptRequest;
use Liberu\Accounting\ReceiptManagement\Models\Receipt;
use Liberu\Accounting\ReceiptManagement\Models\ReceiptAnnotation;
use Liberu\Accounting\ReceiptManagement\Models\ReceiptAudit;
use Liberu\Accounting\ReceiptManagement\Models\ReceiptMatch;
use Liberu\Accounting\ReceiptManagement\ReceiptManagementServiceProvider;

it('registers the receipt management service provider and runs migrations', function (): void {
    expect(app()->getProvider(ReceiptManagementServiceProvider::class))
        ->toBeInstanceOf(ReceiptManagementServiceProvider::class);

    foreach ([
        'accounting_receipts',
        'accounting_receipt_matches',
        'accounting_missing_receipt_requests',
        'accounting_receipt_annotations',
        'accounting_receipt_audits',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('accounting_receipts', [
        'id', 'team_id', 'file_ref', 'source_type', 'source_id', 'merchant', 'amount', 'currency', 'receipt_date', 'status', 'retention_until', 'metadata',
    ]))->toBeTrue();
});

it('exposes all receipt status enum cases', function (): void {
    expect(array_column(ReceiptStatus::cases(), 'value'))
        ->toBe(['inbox', 'matched', 'requested', 'archived', 'purged']);
});

it('ingests receipts idempotently with audit trail and validates file_ref', function (): void {
    $ingest = new IngestReceipt();

    expect(fn () => $ingest->handle(['merchant' => 'Coffee Shop']))
        ->toThrow(InvalidReceipt::class, 'file_ref is required.');

    expect(fn () => $ingest->handle(['file_ref' => '   ']))
        ->toThrow(InvalidReceipt::class, 'file_ref is required.');

    $receipt = $ingest->handle([
        'team_id' => 5,
        'file_ref' => 's3://receipts/2026/08/r-100.pdf',
        'source_type' => 'email',
        'source_id' => 'msg-1',
        'merchant' => 'Office Depot',
        'amount' => 42.50,
        'currency' => 'USD',
        'receipt_date' => '2026-08-20',
        'retention_until' => '2033-08-20',
        'actor_ref' => 'user:10',
        'metadata' => ['pages' => 1],
    ]);

    expect($receipt)->toBeInstanceOf(Receipt::class)
        ->and($receipt->status)->toBe(ReceiptStatus::Inbox)
        ->and($receipt->amount)->toBe('42.50')
        ->and($receipt->currency)->toBe('USD')
        ->and($receipt->metadata)->toBe(['pages' => 1]);

    // Ingesting the same file_ref for the same team reuses the receipt and logs an audit entry
    $reingested = $ingest->handle([
        'team_id' => 5,
        'file_ref' => 's3://receipts/2026/08/r-100.pdf',
        'actor_ref' => 'scanner:2',
    ]);

    expect($reingested->id)->toBe($receipt->id)
        ->and(ReceiptAudit::where('receipt_id', $receipt->id)->count())->toBe(2);

    $audit = ReceiptAudit::where('receipt_id', $receipt->id)->first();
    expect($audit)->toBeInstanceOf(ReceiptAudit::class)
        ->and($audit->action)->toBe('ingested')
        ->and($audit->actor_ref)->toBe('user:10')
        ->and($audit->evidence)->toBe(['file_ref' => 's3://receipts/2026/08/r-100.pdf'])
        ->and($audit->created_at)->not->toBeNull();
});

it('matches receipts, enforces validation and purged guard, and records annotations', function (): void {
    $ingest = new IngestReceipt();
    $match = new MatchReceipt();

    $receipt = $ingest->handle([
        'team_id' => 7,
        'file_ref' => 's3://receipts/r-200.jpg',
        'amount' => 125.00,
        'currency' => 'GBP',
    ]);

    expect(fn () => $match->handle($receipt, ['target_type' => '', 'target_id' => '1']))
        ->toThrow(InvalidReceipt::class, 'A match target is required.');

    expect(fn () => $match->handle($receipt, ['target_type' => 'expense', 'target_id' => '']))
        ->toThrow(InvalidReceipt::class, 'A match target is required.');

    $receiptMatch = $match->handle($receipt, [
        'target_type' => 'expense',
        'target_id' => 88,
        'confidence' => 0.95,
        'actor_ref' => 'bookkeeper:3',
        'metadata' => ['rule' => 'amount+date'],
    ]);

    expect($receiptMatch)->toBeInstanceOf(ReceiptMatch::class)
        ->and($receiptMatch->matched_amount)->toBe('125.00')
        ->and($receiptMatch->confidence)->toBe('0.9500')
        ->and($receiptMatch->status)->toBe('confirmed')
        ->and($receipt->fresh()->status)->toBe(ReceiptStatus::Matched);

    // Repeating the same match returns the existing record
    $repeatMatch = $match->handle($receipt->fresh(), [
        'target_type' => 'expense',
        'target_id' => '88',
        'matched_amount' => 99.00,
    ]);
    expect($repeatMatch->id)->toBe($receiptMatch->id);

    $annotation = ReceiptAnnotation::create([
        'receipt_id' => $receipt->id,
        'author_ref' => 'auditor:1',
        'body' => 'Verified VAT number on receipt.',
        'visibility' => 'team',
        'metadata' => ['flagged' => false],
    ]);

    expect($annotation->metadata)->toBe(['flagged' => false])
        ->and($receipt->matches()->count())->toBe(1)
        ->and($receipt->annotations()->count())->toBe(1);

    // Purged receipt cannot be matched
    $receipt->update(['status' => ReceiptStatus::Purged]);
    expect(fn () => $match->handle($receipt->fresh(), ['target_type' => 'expense', 'target_id' => '89']))
        ->toThrow(InvalidReceipt::class, 'A purged receipt cannot be matched.');
});

it('creates missing receipt requests with and without an existing receipt', function (): void {
    $ingest = new IngestReceipt();
    $requestAction = new RequestMissingReceipt();

    $receipt = $ingest->handle([
        'team_id' => 9,
        'file_ref' => 's3://receipts/r-300.pdf',
    ]);

    $withReceipt = $requestAction->handle([
        'team_id' => 9,
        'receipt_id' => $receipt->id,
        'requestee_ref' => 'employee:42',
        'target_type' => 'card_transaction',
        'target_id' => 'ctx-55',
        'reason' => 'Illegible scan, please re-upload.',
        'due_on' => '2026-09-01',
        'metadata' => ['channel' => 'slack'],
    ]);

    expect($withReceipt)->toBeInstanceOf(MissingReceiptRequest::class)
        ->and($withReceipt->status)->toBe('open')
        ->and($withReceipt->due_on?->toDateString())->toBe('2026-09-01')
        ->and($withReceipt->metadata)->toBe(['channel' => 'slack'])
        ->and($receipt->fresh()->status)->toBe(ReceiptStatus::Requested);

    $withoutReceipt = $requestAction->handle([
        'team_id' => 9,
        'requestee_ref' => 'employee:43',
        'target_type' => 'card_transaction',
        'target_id' => 56,
    ]);

    expect($withoutReceipt->receipt_id)->toBeNull()
        ->and($withoutReceipt->target_id)->toBe('56')
        ->and($withoutReceipt->status)->toBe('open');
});

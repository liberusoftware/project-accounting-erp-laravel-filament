<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\OpeningBalances\Actions\ApproveOpeningBalances;
use Liberu\Accounting\OpeningBalances\Actions\CreateOpeningBalanceBatch;
use Liberu\Accounting\OpeningBalances\Actions\ReconcileOpeningBalances;
use Liberu\Accounting\OpeningBalances\Actions\ValidateOpeningBalances;
use Liberu\Accounting\OpeningBalances\Enums\BalanceType;
use Liberu\Accounting\OpeningBalances\Enums\EntryStatus;
use Liberu\Accounting\OpeningBalances\Enums\OpeningBalanceStatus;
use Liberu\Accounting\OpeningBalances\Events\OpeningBalancesApproved;
use Liberu\Accounting\OpeningBalances\Events\OpeningBalancesValidated;
use Liberu\Accounting\OpeningBalances\Exceptions\InvalidOpeningBalance;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceAudit;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceBatch;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceEntry;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceReconciliation;
use Liberu\Accounting\OpeningBalances\OpeningBalancesServiceProvider;
use Liberu\Accounting\OpeningBalances\Queries\OpeningBalanceQuery;

it('registers the service provider and migrations', function (): void {
    expect(app()->getProviders(OpeningBalancesServiceProvider::class))->not->toBeEmpty()
        ->and(Schema::hasTable('accounting_opening_balance_batches'))->toBeTrue()
        ->and(Schema::hasTable('accounting_opening_balance_entries'))->toBeTrue()
        ->and(Schema::hasTable('accounting_opening_balance_reconciliations'))->toBeTrue()
        ->and(Schema::hasTable('accounting_opening_balance_audits'))->toBeTrue();
});

it('exposes opening balance enums and exception', function (): void {
    expect(array_map(fn (BalanceType $t) => $t->value, BalanceType::cases()))
        ->toBe(['account', 'customer', 'supplier', 'bank', 'item'])
        ->and(array_map(fn (EntryStatus $s) => $s->value, EntryStatus::cases()))
        ->toBe(['pending', 'valid', 'exception', 'reconciled'])
        ->and(array_map(fn (OpeningBalanceStatus $s) => $s->value, OpeningBalanceStatus::cases()))
        ->toBe(['draft', 'validated', 'approved', 'reconciled', 'failed', 'archived']);

    $exception = new InvalidOpeningBalance('Bad opening balance');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad opening balance');
});

it('runs full opening balance workflow: create, validate, approve, reconcile with variance and matched', function (): void {
    $createBatch = new CreateOpeningBalanceBatch();
    $validateBatch = new ValidateOpeningBalances();
    $approveBatch = new ApproveOpeningBalances();
    $reconcileBatch = new ReconcileOpeningBalances();

    $attributes = [
        'team_id' => 50,
        'batch_ref' => 'OB-2026-01',
        'migration_date' => '2026-01-01',
        'currency' => 'gbp',
        'requested_by' => 7,
    ];
    $entries = [
        ['balance_type' => 'bank', 'reference_id' => '1000', 'debit_amount' => 1500.00, 'credit_amount' => 0],
        ['balance_type' => 'customer', 'reference_id' => 'CUST-1', 'document_ref' => 'INV-100', 'debit_amount' => 500.00, 'credit_amount' => 0],
        ['balance_type' => 'supplier', 'reference_id' => 'SUP-1', 'document_ref' => 'BILL-200', 'debit_amount' => 0, 'credit_amount' => 800.00],
        ['balance_type' => 'account', 'reference_id' => '3000', 'debit_amount' => 0, 'credit_amount' => 1200.00],
    ];

    $batch = $createBatch->handle($attributes, $entries);
    expect($batch)->toBeInstanceOf(OpeningBalanceBatch::class)
        ->and($batch->currency)->toBe('GBP')
        ->and($batch->status)->toBe(OpeningBalanceStatus::Draft)
        ->and($batch->entries)->toHaveCount(4)
        ->and($batch->audits()->count())->toBe(1)
        ->and($batch->audits->first())->toBeInstanceOf(OpeningBalanceAudit::class)
        ->and($batch->audits->first()->batch->is($batch))->toBeTrue();

    /** @var OpeningBalanceEntry $bankEntry */
    $bankEntry = $batch->entries[0];
    /** @var OpeningBalanceEntry $supplierEntry */
    $supplierEntry = $batch->entries[2];

    expect($bankEntry->batch->is($batch))->toBeTrue()
        ->and($bankEntry->netAmount())->toBe(1500.00)
        ->and($supplierEntry->netAmount())->toBe(-800.00)
        ->and($bankEntry->currency)->toBe('GBP');

    // Idempotent CreateBatch
    expect($createBatch->handle($attributes, $entries)->id)->toBe($batch->id);

    // Validate
    $validated = $validateBatch->handle($batch);
    expect($validated->status)->toBe(OpeningBalanceStatus::Validated)
        ->and($validated->summary)->toEqual([
            'entry_count' => 4,
            'debit_total' => 2000.0,
            'credit_total' => 2000.0,
        ])
        ->and($validated->entries->every(fn (OpeningBalanceEntry $e) => $e->status === EntryStatus::Valid))->toBeTrue()
        ->and($validated->audits()->count())->toBe(2);

    // Approve
    $approved = $approveBatch->handle($validated, 99);
    expect($approved->status)->toBe(OpeningBalanceStatus::Approved)
        ->and($approved->approved_by)->toBe(99)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->audits()->count())->toBe(3);

    // Reconcile with variance -> remains Approved
    $withVariance = $reconcileBatch->handle($approved, [
        ['entry_id' => $bankEntry->id, 'actual_amount' => 1400.00, 'external_ref' => 'EXT-1', 'notes' => 'Short by 100'],
    ]);
    expect($withVariance->status)->toBe(OpeningBalanceStatus::Approved)
        ->and($withVariance->reconciliations()->count())->toBe(1);

    /** @var OpeningBalanceReconciliation $recon */
    $recon = $withVariance->reconciliations()->first();
    expect($recon->status)->toBe('exception')
        ->and((float) $recon->variance)->toBe(100.00)
        ->and($recon->batch->is($withVariance))->toBeTrue()
        ->and($recon->entry->is($bankEntry))->toBeTrue();

    // Reconcile matched -> becomes Reconciled
    $reconciled = $reconcileBatch->handle($withVariance, [
        ['entry_id' => $bankEntry->id, 'actual_amount' => 1500.00],
        ['entry_id' => $supplierEntry->id, 'actual_amount' => -800.00],
    ]);
    expect($reconciled->status)->toBe(OpeningBalanceStatus::Reconciled)
        ->and($reconciled->reconciliations()->where('status', 'matched')->count())->toBe(2);

    // Events
    Event::fake([OpeningBalancesValidated::class, OpeningBalancesApproved::class]);
    event(new OpeningBalancesValidated($validated));
    event(new OpeningBalancesApproved($approved));
    Event::assertDispatched(OpeningBalancesValidated::class);
    Event::assertDispatched(OpeningBalancesApproved::class);
});

it('handles validation failures, failure recovery, and all InvalidOpeningBalance branches', function (): void {
    $createBatch = new CreateOpeningBalanceBatch();
    $validateBatch = new ValidateOpeningBalances();
    $approveBatch = new ApproveOpeningBalances();
    $reconcileBatch = new ReconcileOpeningBalances();

    expect(fn () => $createBatch->handle(['batch_ref' => '', 'migration_date' => '2026-01-01'], []))
        ->toThrow(InvalidOpeningBalance::class, 'Opening balance field [batch_ref] is required.');

    expect(fn () => $createBatch->handle(['batch_ref' => 'B1', 'migration_date' => '2026-01-01'], []))
        ->toThrow(InvalidOpeningBalance::class, 'An opening balance batch requires at least one entry.');

    expect(fn () => $createBatch->handle(['batch_ref' => 'B1', 'migration_date' => '2026-01-01', 'currency' => 'USD'], [
        ['balance_type' => 'invalid', 'reference_id' => '1', 'debit_amount' => 10],
    ]))->toThrow(InvalidOpeningBalance::class, 'Each entry requires a supported balance type and reference_id.');

    expect(fn () => $createBatch->handle(['batch_ref' => 'B1', 'migration_date' => '2026-01-01', 'currency' => 'USD'], [
        ['balance_type' => 'account', 'reference_id' => '1', 'debit_amount' => 10, 'credit_amount' => 10],
    ]))->toThrow(InvalidOpeningBalance::class, 'Each entry must contain exactly one positive debit or credit amount.');

    expect(fn () => $createBatch->handle(['batch_ref' => 'B1', 'migration_date' => '2026-01-01', 'currency' => 'USD'], [
        ['balance_type' => 'customer', 'reference_id' => 'C1', 'debit_amount' => 50],
    ]))->toThrow(InvalidOpeningBalance::class, 'Customer and supplier entries require an outstanding document reference.');

    // Unbalanced batch fails validation and sets Failed + Exception
    $unbalanced = $createBatch->handle([
        'team_id' => 51,
        'batch_ref' => 'OB-UNBAL',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '100', 'debit_amount' => 100.00],
        ['balance_type' => 'account', 'reference_id' => '200', 'credit_amount' => 60.00],
    ]);

    // Different source data on existing batch_ref throws
    expect(fn () => $createBatch->handle([
        'team_id' => 51,
        'batch_ref' => 'OB-UNBAL',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '100', 'debit_amount' => 200.00],
    ]))->toThrow(InvalidOpeningBalance::class, 'Batch reference already exists with different source data.');

    // Cannot approve or reconcile Draft batch
    expect(fn () => $approveBatch->handle($unbalanced, 1))
        ->toThrow(InvalidOpeningBalance::class, 'Only validated opening balances can be approved.');
    expect(fn () => $reconcileBatch->handle($unbalanced, []))
        ->toThrow(InvalidOpeningBalance::class, 'Only approved opening balances can be reconciled.');

    expect(fn () => $validateBatch->handle($unbalanced))
        ->toThrow(InvalidOpeningBalance::class, 'USD is out of balance by 40');

    $unbalanced->refresh();
    expect($unbalanced->status)->toBe(OpeningBalanceStatus::Failed)
        ->and($unbalanced->failure_message)->toContain('USD is out of balance by 40')
        ->and($unbalanced->entries()->where('status', EntryStatus::Exception)->count())->toBe(2);

    // Fix entries and re-validate Failed batch
    $unbalanced->entries()->where('reference_id', '200')->update(['credit_amount' => 100.00]);
    $revalidated = $validateBatch->handle($unbalanced);
    expect($revalidated->status)->toBe(OpeningBalanceStatus::Validated)
        ->and($revalidated->failure_message)->toBeNull();

    // Cannot validate already Validated batch
    expect(fn () => $validateBatch->handle($revalidated))
        ->toThrow(InvalidOpeningBalance::class, 'Only draft or failed batches can be validated.');

    // Duplicate reference detection during validation
    $dupBatch = $createBatch->handle([
        'team_id' => 51,
        'batch_ref' => 'OB-DUP',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '100', 'debit_amount' => 50.00],
        ['balance_type' => 'account', 'reference_id' => '100', 'credit_amount' => 50.00],
    ]);
    expect(fn () => $validateBatch->handle($dupBatch))
        ->toThrow(InvalidOpeningBalance::class, 'Duplicate balance references were supplied.');

    // Reconcile entry belonging to another batch throws
    $approved = $approveBatch->handle($revalidated, 1);
    $foreignEntry = $dupBatch->entries()->first();
    expect(fn () => $reconcileBatch->handle($approved, [['entry_id' => $foreignEntry->id, 'actual_amount' => 50.0]]))
        ->toThrow(InvalidOpeningBalance::class, 'Reconciliation entry does not belong to this batch.');
});

it('queries opening balance batches with filters and pagination clamping', function (): void {
    $query = new OpeningBalanceQuery();
    expect($query->paginate()->total())->toBe(0);

    $createBatch = new CreateOpeningBalanceBatch();
    $validateBatch = new ValidateOpeningBalances();

    $b1 = $createBatch->handle([
        'team_id' => 70,
        'batch_ref' => 'OB-Q1',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '10', 'debit_amount' => 100],
        ['balance_type' => 'account', 'reference_id' => '20', 'credit_amount' => 100],
    ]);
    $validateBatch->handle($b1);

    $createBatch->handle([
        'team_id' => 71,
        'batch_ref' => 'OB-Q2',
        'migration_date' => '2026-01-02',
        'currency' => 'EUR',
    ], [
        ['balance_type' => 'bank', 'reference_id' => 'B1', 'debit_amount' => 50],
    ]);

    expect($query->paginate()->total())->toBe(2)
        ->and($query->paginate(teamId: 70)->total())->toBe(1)
        ->and($query->paginate(status: 'validated')->total())->toBe(1)
        ->and($query->paginate(perPage: 0)->perPage())->toBe(1)
        ->and($query->paginate(perPage: 500)->perPage())->toBe(100);
});

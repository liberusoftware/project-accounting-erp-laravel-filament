<?php

declare(strict_types=1);

use Liberu\Accounting\PayrollLiabilities\Actions\AllocatePayrollLiability;
use Liberu\Accounting\PayrollLiabilities\Actions\RecordPayrollLiability;
use Liberu\Accounting\PayrollLiabilities\Enums\LiabilityStatus;
use Liberu\Accounting\PayrollLiabilities\Exceptions\InvalidLiability;
use Liberu\Accounting\PayrollLiabilities\Models\PayrollLiability;
use Liberu\Accounting\PayrollLiabilities\PayrollLiabilitiesServiceProvider;
use Liberu\Accounting\PayrollLiabilities\Queries\PayrollLiabilitySummary;

it('registers and boots the payroll liabilities service provider', function (): void {
    $provider = $this->app->register(PayrollLiabilitiesServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(PayrollLiabilitiesServiceProvider::class);
});

it('exposes all payroll liability status enum cases', function (): void {
    expect(array_map(fn (LiabilityStatus $s): string => $s->value, LiabilityStatus::cases()))
        ->toBe(['open', 'part_paid', 'paid', 'exception', 'reconciled']);
});

it('records and allocates payroll liabilities with validation guards', function (): void {
    $record = new RecordPayrollLiability();
    $allocate = new AllocatePayrollLiability();

    expect(fn () => $record->handle(['liability_ref' => '', 'amount' => 100]))
        ->toThrow(InvalidLiability::class, 'Liability reference and valid amount/allocation are required.');

    expect(fn () => $record->handle(['liability_ref' => 'L1', 'amount' => 100, 'paid_amount' => 150]))
        ->toThrow(InvalidLiability::class, 'Liability reference and valid amount/allocation are required.');

    expect(fn () => $record->handle(['liability_ref' => 'L1', 'amount' => 100, 'paid_amount' => -1]))
        ->toThrow(InvalidLiability::class, 'Liability reference and valid amount/allocation are required.');

    $open = $record->handle([
        'team_id' => 12,
        'agency_ref' => 'HMRC',
        'payee_ref' => 'PAYE',
        'liability_ref' => 'LIB-001',
        'currency' => 'GBP',
        'amount' => 500.00,
        'due_on' => now()->subDays(5)->toDateString(),
        'metadata' => ['period' => '2026-01'],
    ]);

    expect($open)->toBeInstanceOf(PayrollLiability::class)
        ->and($open->status)->toBe(LiabilityStatus::Open)
        ->and($open->outstanding())->toBe(500.0);

    // Upsert with same ref and full paid_amount marks Paid
    $paidOnRecord = $record->handle([
        'team_id' => 12,
        'liability_ref' => 'LIB-002',
        'amount' => 200.00,
        'paid_amount' => 200.00,
    ]);
    expect($paidOnRecord->status)->toBe(LiabilityStatus::Paid)
        ->and($paidOnRecord->outstanding())->toBe(0.0);

    expect(fn () => $allocate->handle($open, 0, 'ALLOC-1'))
        ->toThrow(InvalidLiability::class, 'Allocation exceeds the outstanding liability or lacks a reference.');

    expect(fn () => $allocate->handle($open, 600, 'ALLOC-1'))
        ->toThrow(InvalidLiability::class, 'Allocation exceeds the outstanding liability or lacks a reference.');

    expect(fn () => $allocate->handle($open, 100, ''))
        ->toThrow(InvalidLiability::class, 'Allocation exceeds the outstanding liability or lacks a reference.');

    $partPaid = $allocate->handle($open, 200.00, 'ALLOC-1');
    expect($partPaid->status)->toBe(LiabilityStatus::PartPaid)
        ->and($partPaid->allocation_ref)->toBe('ALLOC-1')
        ->and($partPaid->payment_ref)->toBe('ALLOC-1')
        ->and($partPaid->outstanding())->toBe(300.0);

    $fullyPaid = $allocate->handle($partPaid, 300.00, 'ALLOC-2');
    expect($fullyPaid->status)->toBe(LiabilityStatus::Paid)
        ->and($fullyPaid->outstanding())->toBe(0.0);
});

it('summarizes payroll liabilities including overdue, exceptions, and reconciled counts', function (): void {
    $summary = new PayrollLiabilitySummary();
    expect($summary->forTeam(99))->toBe([
        'count' => 0,
        'amount' => 0.0,
        'paid' => 0.0,
        'outstanding' => 0.0,
        'overdue' => 0,
        'exceptions' => 0,
        'reconciled' => 0,
    ]);

    $record = new RecordPayrollLiability();
    $record->handle([
        'team_id' => 15,
        'liability_ref' => 'L-OVERDUE',
        'amount' => 400,
        'paid_amount' => 100,
        'due_on' => now()->subDay()->toDateString(),
    ]);
    $record->handle([
        'team_id' => 15,
        'liability_ref' => 'L-EXC',
        'amount' => 100,
        'due_on' => now()->addDays(10)->toDateString(),
        'status' => LiabilityStatus::Exception,
    ]);
    $record->handle([
        'team_id' => 15,
        'liability_ref' => 'L-REC',
        'amount' => 300,
        'paid_amount' => 300,
        'status' => LiabilityStatus::Reconciled,
    ]);

    expect($summary->forTeam(15))->toBe([
        'count' => 3,
        'amount' => 800.0,
        'paid' => 400.0,
        'outstanding' => 400.0,
        'overdue' => 1,
        'exceptions' => 1,
        'reconciled' => 1,
    ]);
});

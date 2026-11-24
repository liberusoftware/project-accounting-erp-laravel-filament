<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\DebtAndLoans\Actions\CreateDebtFacility;
use Liberu\Accounting\DebtAndLoans\Actions\ReconcileDebtMovement;
use Liberu\Accounting\DebtAndLoans\Actions\RecordDebtMovement;
use Liberu\Accounting\DebtAndLoans\DebtAndLoansServiceProvider;
use Liberu\Accounting\DebtAndLoans\Enums\DebtFacilityStatus;
use Liberu\Accounting\DebtAndLoans\Enums\DebtMovementKind;
use Liberu\Accounting\DebtAndLoans\Enums\DebtMovementStatus;
use Liberu\Accounting\DebtAndLoans\Exceptions\InvalidDebt;
use Liberu\Accounting\DebtAndLoans\Models\DebtCovenant;
use Liberu\Accounting\DebtAndLoans\Models\DebtFacility;
use Liberu\Accounting\DebtAndLoans\Models\DebtMovement;
use Liberu\Accounting\DebtAndLoans\Queries\DebtQuery;

it('registers and boots the debt and loans service provider and migrations', function (): void {
    $provider = app()->getProvider(DebtAndLoansServiceProvider::class)
        ?? new DebtAndLoansServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DebtAndLoansServiceProvider::class)
        ->and(Schema::hasTable('accounting_debt_facilities'))->toBeTrue()
        ->and(Schema::hasTable('accounting_debt_movements'))->toBeTrue()
        ->and(Schema::hasTable('accounting_debt_covenants'))->toBeTrue();
});

it('exposes debt enums and invalid debt exception', function (): void {
    expect(array_map(static fn (DebtFacilityStatus $s): string => $s->value, DebtFacilityStatus::cases()))
        ->toBe(['active', 'closed'])
        ->and(array_map(static fn (DebtMovementKind $k): string => $k->value, DebtMovementKind::cases()))
        ->toBe(['drawdown', 'repayment', 'interest', 'fee'])
        ->and(array_map(static fn (DebtMovementStatus $s): string => $s->value, DebtMovementStatus::cases()))
        ->toBe(['scheduled', 'posted', 'reconciled']);

    $exception = new InvalidDebt('Debt error.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Debt error.');
});

it('creates debt facilities and validates required fields and numeric bounds', function (): void {
    $action = app(CreateDebtFacility::class);

    $facility = $action->handle([
        'team_id' => 10,
        'facility_ref' => 'RCF-2026',
        'lender_ref' => 'BARCLAYS-UK',
        'currency' => 'GBP',
        'limit_amount' => 500000.00,
        'interest_rate' => 0.0525,
        'start_date' => '2026-01-01',
        'maturity_date' => '2029-01-01',
        'metadata' => ['secured' => true],
    ]);

    expect($facility)->toBeInstanceOf(DebtFacility::class)
        ->and($facility->status)->toBe(DebtFacilityStatus::Active)
        ->and((float) $facility->limit_amount)->toBe(500000.0)
        ->and((float) $facility->drawn_amount)->toBe(0.0)
        ->and((float) $facility->interest_rate)->toBe(0.0525)
        ->and($facility->start_date->toDateString())->toBe('2026-01-01')
        ->and($facility->maturity_date->toDateString())->toBe('2029-01-01')
        ->and($facility->metadata)->toBe(['secured' => true]);

    $covenant = DebtCovenant::create([
        'facility_id' => $facility->id,
        'team_id' => 10,
        'covenant_ref' => 'COV-LEVERAGE',
        'metric' => 'net_debt_to_ebitda',
        'threshold' => 3.5,
        'operator' => 'lte',
        'last_value' => 2.1,
        'status' => 'compliant',
        'metadata' => ['tested_at' => '2026-06-30'],
    ]);

    expect($covenant->facility->is($facility))->toBeTrue()
        ->and((float) $covenant->threshold)->toBe(3.5)
        ->and((float) $covenant->last_value)->toBe(2.1)
        ->and($covenant->metadata)->toBe(['tested_at' => '2026-06-30'])
        ->and($facility->covenants)->toHaveCount(1);
});

it('rejects invalid debt facility creation payloads', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'facility_ref' => 'FAC-1',
        'lender_ref' => 'LEND-1',
        'currency' => 'USD',
        'limit_amount' => 10000,
        'interest_rate' => 0.04,
        'start_date' => '2026-01-01',
        'maturity_date' => '2028-01-01',
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateDebtFacility::class)->handle($payload))
        ->toThrow(InvalidDebt::class, $expectedMessage);
})->with([
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank facility_ref' => [['facility_ref' => ''], 'facility_ref is required.'],
    'blank lender_ref' => [['lender_ref' => ' '], 'lender_ref is required.'],
    'blank currency' => [['currency' => ''], 'currency is required.'],
    'missing limit_amount' => [['limit_amount' => null], 'limit_amount is required.'],
    'missing start_date' => [['start_date' => null], 'start_date is required.'],
    'missing maturity_date' => [['maturity_date' => null], 'maturity_date is required.'],
    'zero limit_amount' => [['limit_amount' => 0], 'Facility limit must be positive and interest cannot be negative.'],
    'negative limit_amount' => [['limit_amount' => -100], 'Facility limit must be positive and interest cannot be negative.'],
    'negative interest_rate' => [['interest_rate' => -0.01], 'Facility limit must be positive and interest cannot be negative.'],
]);

it('records drawdowns, repayments, interest, fees and reconciles movements', function (): void {
    $facility = app(CreateDebtFacility::class)->handle([
        'team_id' => 20,
        'facility_ref' => 'TERM-20',
        'lender_ref' => 'HSBC',
        'currency' => 'GBP',
        'limit_amount' => 1000.00,
        'start_date' => '2026-01-01',
        'maturity_date' => '2028-01-01',
    ]);

    $record = app(RecordDebtMovement::class);
    $reconcile = app(ReconcileDebtMovement::class);

    // Validation failures
    expect(fn () => $record->handle($facility, 'invalid_kind', ['movement_date' => '2026-02-01', 'principal_amount' => 100]))
        ->toThrow(InvalidDebt::class, 'A valid movement kind and date are required.')
        ->and(fn () => $record->handle($facility, DebtMovementKind::Drawdown, ['movement_date' => '']))
        ->toThrow(InvalidDebt::class, 'A valid movement kind and date are required.')
        ->and(fn () => $record->handle($facility, DebtMovementKind::Drawdown, ['movement_date' => '2026-02-01', 'principal_amount' => -10]))
        ->toThrow(InvalidDebt::class, 'Debt movement amounts must be non-negative and non-zero.')
        ->and(fn () => $record->handle($facility, DebtMovementKind::Drawdown, ['movement_date' => '2026-02-01', 'principal_amount' => 0, 'interest_amount' => 0, 'fee_amount' => 0]))
        ->toThrow(InvalidDebt::class, 'Debt movement amounts must be non-negative and non-zero.')
        ->and(fn () => $record->handle($facility, DebtMovementKind::Drawdown, ['movement_date' => '2026-02-01', 'principal_amount' => 1001]))
        ->toThrow(InvalidDebt::class, 'Drawdown exceeds the facility limit.')
        ->and(fn () => $record->handle($facility, DebtMovementKind::Repayment, ['movement_date' => '2026-02-01', 'principal_amount' => 10]))
        ->toThrow(InvalidDebt::class, 'Repayment exceeds the outstanding principal.');

    // Drawdown increments drawn_amount
    $drawdown = $record->handle($facility, DebtMovementKind::Drawdown, [
        'principal_amount' => 600,
        'movement_date' => '2026-02-01',
        'due_date' => '2026-08-01',
        'metadata' => ['tranche' => 1],
    ]);
    $facility->refresh();

    expect($drawdown)->toBeInstanceOf(DebtMovement::class)
        ->and($drawdown->kind)->toBe(DebtMovementKind::Drawdown)
        ->and($drawdown->status)->toBe(DebtMovementStatus::Scheduled)
        ->and($drawdown->facility->is($facility))->toBeTrue()
        ->and((float) $facility->drawn_amount)->toBe(600.0);

    // Repayment (string kind) decrements drawn_amount
    $repayment = $record->handle($facility, 'repayment', [
        'principal_amount' => 250,
        'interest_amount' => 15,
        'movement_date' => '2026-03-01',
        'due_date' => '2026-03-01',
    ]);
    $facility->refresh();
    expect((float) $facility->drawn_amount)->toBe(350.0);

    // Interest and fee movements leave drawn_amount untouched
    $interest = $record->handle($facility, DebtMovementKind::Interest, [
        'interest_amount' => 12.50,
        'movement_date' => '2026-04-01',
    ]);
    $fee = $record->handle($facility, DebtMovementKind::Fee, [
        'fee_amount' => 25.00,
        'movement_date' => '2026-04-02',
    ]);
    expect((float) $facility->fresh()->drawn_amount)->toBe(350.0)
        ->and((float) $interest->interest_amount)->toBe(12.5)
        ->and((float) $fee->fee_amount)->toBe(25.0);

    // Reconcile validation & happy path
    expect(fn () => $reconcile->handle($repayment, ''))
        ->toThrow(InvalidDebt::class, 'Only scheduled movements with a journal reference can be reconciled.');

    $reconciled = $reconcile->handle($repayment, 'JRN-DEBT-001');
    expect($reconciled->status)->toBe(DebtMovementStatus::Reconciled)
        ->and($reconciled->journal_ref)->toBe('JRN-DEBT-001')
        ->and($reconciled->relationLoaded('facility'))->toBeTrue();

    expect(fn () => $reconcile->handle($reconciled, 'JRN-DEBT-002'))
        ->toThrow(InvalidDebt::class);
});

it('queries facilities and computes debt position across current and non-current maturities', function (): void {
    Carbon::setTestNow('2026-08-29 00:00:00');

    $query = app(DebtQuery::class);

    expect($query->facilities(999))->toBeEmpty()
        ->and($query->position(999))->toBe([
            'facilities' => 0,
            'outstanding' => 0.0,
            'current_due_next_year' => 0.0,
            'non_current' => 0.0,
        ]);

    $create = app(CreateDebtFacility::class);
    $record = app(RecordDebtMovement::class);
    $reconcile = app(ReconcileDebtMovement::class);

    $facility = $create->handle([
        'team_id' => 30,
        'facility_ref' => 'FAC-30',
        'lender_ref' => 'LLOYDS',
        'currency' => 'GBP',
        'limit_amount' => 5000,
        'start_date' => '2026-01-01',
        'maturity_date' => '2030-01-01',
    ]);

    // Draw 1000 total
    $record->handle($facility, DebtMovementKind::Drawdown, [
        'principal_amount' => 1000,
        'movement_date' => '2026-08-29',
    ]);

    // Scheduled repayment of 300 within next year -> counted in current_due_next_year
    $record->handle($facility, DebtMovementKind::Repayment, [
        'principal_amount' => 300,
        'movement_date' => '2026-08-29',
        'due_date' => '2027-02-01',
    ]);

    // Reconciled repayment within next year -> excluded from current_due_next_year
    $reconciledMove = $record->handle($facility, DebtMovementKind::Repayment, [
        'principal_amount' => 100,
        'movement_date' => '2026-08-29',
        'due_date' => '2026-12-01',
    ]);
    $reconcile->handle($reconciledMove, 'JRN-30');

    // Scheduled repayment due > 1 year out -> excluded from current_due_next_year
    $record->handle($facility, DebtMovementKind::Repayment, [
        'principal_amount' => 200,
        'movement_date' => '2026-08-29',
        'due_date' => '2028-06-01',
    ]);
    // Outstanding is now 1000 - 300 - 100 - 200 = 400. Current due next year = 300. Non-current = 100.

    $facilities = $query->facilities(30);
    $position = $query->position(30);

    expect($facilities)->toHaveCount(1)
        ->and($facilities->first()->relationLoaded('movements'))->toBeTrue()
        ->and($facilities->first()->relationLoaded('covenants'))->toBeTrue()
        ->and($position)->toBe([
            'facilities' => 1,
            'outstanding' => 400.0,
            'current_due_next_year' => 300.0,
            'non_current' => 100.0,
        ]);

    Carbon::setTestNow();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\DepositsAndClearing\Actions\CreateGroupedDeposit;
use Liberu\Accounting\DepositsAndClearing\Actions\ReconcileDeposit;
use Liberu\Accounting\DepositsAndClearing\Actions\RecordUndepositedFund;
use Liberu\Accounting\DepositsAndClearing\DepositsAndClearingServiceProvider;
use Liberu\Accounting\DepositsAndClearing\Enums\ClearingDepositStatus;
use Liberu\Accounting\DepositsAndClearing\Enums\ClearingFundStatus;
use Liberu\Accounting\DepositsAndClearing\Exceptions\InvalidClearing;
use Liberu\Accounting\DepositsAndClearing\Models\ClearingDeposit;
use Liberu\Accounting\DepositsAndClearing\Models\ClearingFund;
use Liberu\Accounting\DepositsAndClearing\Queries\ClearingQuery;

it('registers and boots the deposits and clearing service provider and migrations', function (): void {
    $provider = app()->getProvider(DepositsAndClearingServiceProvider::class)
        ?? new DepositsAndClearingServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DepositsAndClearingServiceProvider::class)
        ->and(Schema::hasTable('accounting_clearing_deposits'))->toBeTrue()
        ->and(Schema::hasTable('accounting_clearing_funds'))->toBeTrue();
});

it('exposes clearing enums and invalid clearing exception', function (): void {
    expect(array_map(static fn (ClearingDepositStatus $s): string => $s->value, ClearingDepositStatus::cases()))
        ->toBe(['open', 'reconciled'])
        ->and(array_map(static fn (ClearingFundStatus $s): string => $s->value, ClearingFundStatus::cases()))
        ->toBe(['undeposited', 'grouped', 'reconciled']);

    $exception = new InvalidClearing('Clearing error.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Clearing error.');
});

it('records undeposited funds and validates required fields and positive amount', function (): void {
    $action = app(RecordUndepositedFund::class);

    $fund = $action->handle([
        'team_id' => 10,
        'source_type' => 'card_capture',
        'source_id' => 'ch_001',
        'amount' => 125.75,
        'currency' => 'GBP',
        'received_on' => '2026-08-29',
        'metadata' => ['terminal' => 'pos-1'],
    ]);

    expect($fund)->toBeInstanceOf(ClearingFund::class)
        ->and($fund->status)->toBe(ClearingFundStatus::Undeposited)
        ->and((float) $fund->amount)->toBe(125.75)
        ->and($fund->received_on->toDateString())->toBe('2026-08-29')
        ->and($fund->metadata)->toBe(['terminal' => 'pos-1'])
        ->and($fund->deposit)->toBeNull();
});

it('rejects invalid undeposited fund payloads', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'source_type' => 'receipt',
        'source_id' => 'rcp-1',
        'amount' => 50,
        'currency' => 'USD',
        'received_on' => '2026-08-29',
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(RecordUndepositedFund::class)->handle($payload))
        ->toThrow(InvalidClearing::class, $expectedMessage);
})->with([
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank source_type' => [['source_type' => ''], 'source_type is required.'],
    'blank source_id' => [['source_id' => ' '], 'source_id is required.'],
    'missing amount' => [['amount' => null], 'amount is required.'],
    'blank currency' => [['currency' => ''], 'currency is required.'],
    'missing received_on' => [['received_on' => null], 'received_on is required.'],
    'zero amount' => [['amount' => 0], 'Fund amount must be positive.'],
    'negative amount' => [['amount' => -20], 'Fund amount must be positive.'],
]);

it('groups undeposited funds into a deposit and reconciles payouts and fees', function (): void {
    $record = app(RecordUndepositedFund::class);
    $group = app(CreateGroupedDeposit::class);
    $reconcile = app(ReconcileDeposit::class);

    $f1 = $record->handle([
        'team_id' => 20,
        'source_type' => 'stripe',
        'source_id' => 'pi_1',
        'amount' => 100.00,
        'currency' => 'USD',
        'received_on' => '2026-08-28',
    ]);
    $f2 = $record->handle([
        'team_id' => 20,
        'source_type' => 'stripe',
        'source_id' => 'pi_2',
        'amount' => 50.00,
        'currency' => 'USD',
        'received_on' => '2026-08-28',
    ]);
    $fEur = $record->handle([
        'team_id' => 20,
        'source_type' => 'stripe',
        'source_id' => 'pi_3',
        'amount' => 25.00,
        'currency' => 'EUR',
        'received_on' => '2026-08-28',
    ]);
    $fOtherTeam = $record->handle([
        'team_id' => 21,
        'source_type' => 'stripe',
        'source_id' => 'pi_4',
        'amount' => 10.00,
        'currency' => 'USD',
        'received_on' => '2026-08-28',
    ]);

    // Validation failures in CreateGroupedDeposit
    expect(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => '', 'account_ref' => 'BANK-1', 'currency' => 'USD', 'deposit_date' => '2026-08-29'], [$f1->id]))
        ->toThrow(InvalidClearing::class, 'deposit_ref is required.')
        ->and(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => 'DEP-1', 'account_ref' => 'BANK-1', 'currency' => 'USD', 'deposit_date' => '2026-08-29'], []))
        ->toThrow(InvalidClearing::class, 'At least one undeposited fund is required.')
        ->and(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => 'DEP-1', 'account_ref' => 'BANK-1', 'currency' => 'USD', 'deposit_date' => '2026-08-29'], [$f1->id, $fOtherTeam->id]))
        ->toThrow(InvalidClearing::class, 'Funds must be available, unique, and in the deposit currency.')
        ->and(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => 'DEP-1', 'account_ref' => 'BANK-1', 'currency' => 'USD', 'deposit_date' => '2026-08-29'], [$f1->id, $fEur->id]))
        ->toThrow(InvalidClearing::class, 'Funds must be available, unique, and in the deposit currency.')
        ->and(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => 'DEP-1', 'account_ref' => 'BANK-1', 'currency' => 'GBP', 'deposit_date' => '2026-08-29'], [$f1->id]))
        ->toThrow(InvalidClearing::class, 'Funds must be available, unique, and in the deposit currency.');

    // Happy path grouping
    $deposit = $group->handle([
        'team_id' => 20,
        'deposit_ref' => 'DEP-20-001',
        'provider' => 'stripe',
        'account_ref' => 'BANK-USD',
        'currency' => 'USD',
        'deposit_date' => '2026-08-29',
        'metadata' => ['batch' => 'am'],
    ], [$f1->id, $f2->id]);

    expect($deposit)->toBeInstanceOf(ClearingDeposit::class)
        ->and($deposit->status)->toBe(ClearingDepositStatus::Open)
        ->and((float) $deposit->gross_amount)->toBe(150.0)
        ->and((float) $deposit->payout_amount)->toBe(150.0)
        ->and($deposit->deposit_date->toDateString())->toBe('2026-08-29')
        ->and($deposit->metadata)->toBe(['batch' => 'am'])
        ->and($deposit->relationLoaded('funds'))->toBeTrue()
        ->and($deposit->funds)->toHaveCount(2)
        ->and($f1->fresh()->status)->toBe(ClearingFundStatus::Grouped)
        ->and($f1->fresh()->deposit->is($deposit))->toBeTrue();

    // Already grouped fund cannot be grouped again.
    expect(fn () => $group->handle(['team_id' => 20, 'deposit_ref' => 'DEP-20-002', 'account_ref' => 'BANK-USD', 'currency' => 'USD', 'deposit_date' => '2026-08-29'], [$f1->id]))
        ->toThrow(InvalidClearing::class);

    // Reconcile validation failures
    expect(fn () => $reconcile->handle($deposit, -1, 151))
        ->toThrow(InvalidClearing::class, 'Payout plus fees must equal the gross deposit.')
        ->and(fn () => $reconcile->handle($deposit, 151, -1))
        ->toThrow(InvalidClearing::class)
        ->and(fn () => $reconcile->handle($deposit, 140, 5))
        ->toThrow(InvalidClearing::class);

    // Reconcile happy path
    $reconciled = $reconcile->handle($deposit, 145.50, 4.50);

    expect($reconciled->status)->toBe(ClearingDepositStatus::Reconciled)
        ->and((float) $reconciled->payout_amount)->toBe(145.5)
        ->and((float) $reconciled->fee_amount)->toBe(4.5)
        ->and($reconciled->relationLoaded('funds'))->toBeTrue()
        ->and($reconciled->funds->every(fn (ClearingFund $f): bool => $f->status === ClearingFundStatus::Reconciled))->toBeTrue();
});

it('queries undeposited funds and deposits scoped by team', function (): void {
    $query = app(ClearingQuery::class);

    expect($query->undeposited(999))->toBeEmpty()
        ->and($query->deposits(999))->toBeEmpty();

    $record = app(RecordUndepositedFund::class);
    $group = app(CreateGroupedDeposit::class);

    $openFund = $record->handle([
        'team_id' => 30,
        'source_type' => 'cash',
        'source_id' => 'cash-1',
        'amount' => 40,
        'currency' => 'GBP',
        'received_on' => '2026-08-29',
    ]);
    $groupedFund = $record->handle([
        'team_id' => 30,
        'source_type' => 'cash',
        'source_id' => 'cash-2',
        'amount' => 60,
        'currency' => 'GBP',
        'received_on' => '2026-08-29',
    ]);
    $deposit = $group->handle([
        'team_id' => 30,
        'deposit_ref' => 'DEP-30',
        'account_ref' => 'BANK-30',
        'currency' => 'GBP',
        'deposit_date' => '2026-08-29',
    ], [$groupedFund->id]);

    $record->handle([
        'team_id' => 31,
        'source_type' => 'cash',
        'source_id' => 'cash-3',
        'amount' => 99,
        'currency' => 'GBP',
        'received_on' => '2026-08-29',
    ]);

    $undeposited = $query->undeposited(30);
    $deposits = $query->deposits(30);

    expect($undeposited)->toHaveCount(1)
        ->and($undeposited->first()->id)->toBe($openFund->id)
        ->and($deposits)->toHaveCount(1)
        ->and($deposits->first()->id)->toBe($deposit->id)
        ->and($deposits->first()->relationLoaded('funds'))->toBeTrue();
});

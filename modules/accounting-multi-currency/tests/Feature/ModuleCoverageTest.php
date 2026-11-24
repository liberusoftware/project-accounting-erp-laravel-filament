<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\MultiCurrency\Actions\ConfigureCurrency;
use Liberu\Accounting\MultiCurrency\Actions\CreateRevaluation;
use Liberu\Accounting\MultiCurrency\Actions\RecordExchangeRate;
use Liberu\Accounting\MultiCurrency\Enums\CurrencyRole;
use Liberu\Accounting\MultiCurrency\Enums\GainStatus;
use Liberu\Accounting\MultiCurrency\Enums\RevaluationStatus;
use Liberu\Accounting\MultiCurrency\Events\RateRecorded;
use Liberu\Accounting\MultiCurrency\Events\RevaluationCalculated;
use Liberu\Accounting\MultiCurrency\Exceptions\InvalidCurrency;
use Liberu\Accounting\MultiCurrency\Models\CurrencyProfile;
use Liberu\Accounting\MultiCurrency\Models\CurrencyReconciliation;
use Liberu\Accounting\MultiCurrency\Models\ExchangeRate;
use Liberu\Accounting\MultiCurrency\Models\RevaluationPosition;
use Liberu\Accounting\MultiCurrency\Models\RevaluationRun;
use Liberu\Accounting\MultiCurrency\MultiCurrencyServiceProvider;
use Liberu\Accounting\MultiCurrency\Queries\CurrencyQuery;

it('registers the service provider and migrations', function (): void {
    expect(app()->getProviders(MultiCurrencyServiceProvider::class))->not->toBeEmpty()
        ->and(Schema::hasTable('accounting_multi_currency_profiles'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_currency_rates'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_currency_revaluations'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_currency_positions'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_currency_reconciliations'))->toBeTrue();
});

it('exposes multi-currency enums and exception', function (): void {
    expect(array_map(fn (CurrencyRole $r) => $r->value, CurrencyRole::cases()))
        ->toBe(['transaction', 'functional', 'reporting'])
        ->and(array_map(fn (GainStatus $g) => $g->value, GainStatus::cases()))
        ->toBe(['unrealized', 'realized', 'reconciled'])
        ->and(array_map(fn (RevaluationStatus $s) => $s->value, RevaluationStatus::cases()))
        ->toBe(['draft', 'calculated', 'posted', 'reconciled', 'failed']);

    $exception = new InvalidCurrency('Bad currency');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad currency');
});

it('configures currency profiles, records exchange rates, calculates revaluations, and reconciles', function (): void {
    $configure = new ConfigureCurrency();
    $recordRate = new RecordExchangeRate();
    $createReval = new CreateRevaluation();

    $profile = $configure->handle([
        'team_id' => 6,
        'scope_ref' => 'entity:uk-hq',
        'currency' => 'gbp',
        'role' => 'functional',
        'metadata' => ['default' => true],
    ]);
    expect($profile)->toBeInstanceOf(CurrencyProfile::class)
        ->and($profile->currency)->toBe('GBP')
        ->and($profile->role)->toBe(CurrencyRole::Functional)
        ->and($profile->is_active)->toBeTrue();

    // updateOrCreate updates existing profile
    $updatedProfile = $configure->handle([
        'team_id' => 6,
        'scope_ref' => 'entity:uk-hq',
        'currency' => 'eur',
        'role' => 'functional',
        'is_active' => false,
    ]);
    expect($updatedProfile->id)->toBe($profile->id)
        ->and($updatedProfile->currency)->toBe('EUR')
        ->and($updatedProfile->is_active)->toBeFalse();

    $rate = $recordRate->handle([
        'team_id' => 6,
        'from_currency' => 'usd',
        'to_currency' => 'gbp',
        'rate_date' => '2026-03-31',
        'rate' => 0.7850,
        'source' => 'ecb',
        'rate_type' => 'closing',
    ]);
    expect($rate)->toBeInstanceOf(ExchangeRate::class)
        ->and($rate->from_currency)->toBe('USD')
        ->and($rate->to_currency)->toBe('GBP')
        ->and((float) $rate->rate)->toBe(0.785);

    // Revaluation covering unrealized gain, unrealized loss, realized gain, realized loss
    $attributes = [
        'team_id' => 6,
        'run_ref' => 'REV-2026-03',
        'scope_ref' => 'entity:uk-hq',
        'as_of_date' => '2026-03-31',
        'functional_currency' => 'gbp',
        'requested_by' => 42,
    ];
    $positions = [
        // Unrealized gain: 1000 * (0.85 - 0.80) = +50
        ['reference_type' => 'invoice', 'reference_id' => 'INV-1', 'currency' => 'usd', 'foreign_amount' => 1000, 'book_rate' => 0.80, 'closing_rate' => 0.85],
        // Unrealized loss: 1000 * (0.75 - 0.80) = -50 -> loss 50 (make it -30 so net != 0)
        ['reference_type' => 'invoice', 'reference_id' => 'INV-2', 'currency' => 'eur', 'foreign_amount' => 1000, 'book_rate' => 0.88, 'closing_rate' => 0.85],
        // Realized gain: 500 * (0.90 - 0.80) = +50
        ['reference_type' => 'payment', 'reference_id' => 'PAY-1', 'currency' => 'usd', 'foreign_amount' => 500, 'book_rate' => 0.80, 'closing_rate' => 0.90, 'gain_status' => 'realized'],
        // Realized loss: 500 * (0.78 - 0.80) = -10
        ['reference_type' => 'payment', 'reference_id' => 'PAY-2', 'currency' => 'cad', 'foreign_amount' => 500, 'book_rate' => 0.80, 'closing_rate' => 0.78, 'gain_status' => 'realized'],
    ];

    $run = $createReval->handle($attributes, $positions);
    expect($run)->toBeInstanceOf(RevaluationRun::class)
        ->and($run->functional_currency)->toBe('GBP')
        ->and($run->status)->toBe(RevaluationStatus::Calculated)
        ->and((float) $run->unrealized_gain)->toBe(50.00)
        ->and((float) $run->unrealized_loss)->toBe(30.00)
        ->and((float) $run->realized_gain)->toBe(50.00)
        ->and((float) $run->realized_loss)->toBe(10.00)
        ->and($run->summary)->toBe([
            'position_count' => 4,
            'net_unrealized' => 20.0,
            'net_realized' => 40.0,
        ])
        ->and($run->positions)->toHaveCount(4)
        ->and($run->positions->first())->toBeInstanceOf(RevaluationPosition::class)
        ->and($run->positions->first()->run->is($run))->toBeTrue();

    // Idempotent duplicate run_ref
    $sameRun = $createReval->handle($attributes, $positions);
    expect($sameRun->id)->toBe($run->id)
        ->and($sameRun->positions)->toHaveCount(4);

    // CurrencyReconciliation model & relationship
    $recon = $run->reconciliations()->create([
        'reference_type' => 'invoice',
        'reference_id' => 'INV-1',
        'expected_gain_loss' => 50.00,
        'actual_gain_loss' => 50.00,
        'variance' => 0.00,
        'status' => 'matched',
    ]);
    expect($recon)->toBeInstanceOf(CurrencyReconciliation::class)
        ->and($recon->run->is($run))->toBeTrue()
        ->and($run->reconciliations()->count())->toBe(1);

    // Events
    Event::fake([RateRecorded::class, RevaluationCalculated::class]);
    event(new RateRecorded($rate));
    event(new RevaluationCalculated($run));
    Event::assertDispatched(RateRecorded::class, fn (RateRecorded $e) => $e->rate->is($rate));
    Event::assertDispatched(RevaluationCalculated::class, fn (RevaluationCalculated $e) => $e->run->is($run));
});

it('throws InvalidCurrency on invalid profile, rate, or revaluation inputs', function (): void {
    $configure = new ConfigureCurrency();
    $recordRate = new RecordExchangeRate();
    $createReval = new CreateRevaluation();

    expect(fn () => $configure->handle(['scope_ref' => 's1', 'currency' => 'US', 'role' => 'functional']))
        ->toThrow(InvalidCurrency::class);
    expect(fn () => $configure->handle(['scope_ref' => 's1', 'currency' => 'USD', 'role' => 'unknown']))
        ->toThrow(InvalidCurrency::class);

    expect(fn () => $recordRate->handle([
        'from_currency' => 'USD',
        'to_currency' => 'USD',
        'rate_date' => '2026-01-01',
        'rate' => 1.0,
    ]))->toThrow(InvalidCurrency::class);
    expect(fn () => $recordRate->handle([
        'from_currency' => 'USD',
        'to_currency' => 'GBP',
        'rate_date' => '2026-01-01',
        'rate' => 0,
    ]))->toThrow(InvalidCurrency::class);

    expect(fn () => $createReval->handle(['run_ref' => '', 'as_of_date' => '2026-01-01', 'functional_currency' => 'GBP'], []))
        ->toThrow(InvalidCurrency::class, 'Revaluation field [run_ref] is required.');

    expect(fn () => $createReval->handle(['run_ref' => 'R1', 'as_of_date' => '2026-01-01', 'functional_currency' => 'GBP'], []))
        ->toThrow(InvalidCurrency::class, 'A revaluation requires at least one foreign-currency position.');

    // Position with same currency as functional_currency
    expect(fn () => $createReval->handle([
        'run_ref' => 'R-BAD-POS',
        'as_of_date' => '2026-01-01',
        'functional_currency' => 'GBP',
    ], [
        ['reference_id' => '1', 'currency' => 'GBP', 'foreign_amount' => 100, 'book_rate' => 1, 'closing_rate' => 1],
    ]))->toThrow(InvalidCurrency::class, 'Each position needs a distinct currency, amount, and positive book/closing rates.');

    // Duplicate run_ref with different source_hash
    $createReval->handle([
        'team_id' => 9,
        'run_ref' => 'R-DUP',
        'as_of_date' => '2026-01-01',
        'functional_currency' => 'GBP',
    ], [
        ['reference_id' => '1', 'currency' => 'USD', 'foreign_amount' => 100, 'book_rate' => 0.8, 'closing_rate' => 0.85],
    ]);

    expect(fn () => $createReval->handle([
        'team_id' => 9,
        'run_ref' => 'R-DUP',
        'as_of_date' => '2026-01-01',
        'functional_currency' => 'GBP',
    ], [
        ['reference_id' => '1', 'currency' => 'USD', 'foreign_amount' => 200, 'book_rate' => 0.8, 'closing_rate' => 0.85],
    ]))->toThrow(InvalidCurrency::class, 'Revaluation reference already exists with different source data.');
});

it('queries exchange rates, revaluations, and revaluation reports', function (): void {
    $query = new CurrencyQuery();

    expect($query->rates()->total())->toBe(0)
        ->and($query->revaluations()->total())->toBe(0);

    $recordRate = new RecordExchangeRate();
    $createReval = new CreateRevaluation();

    $recordRate->handle(['team_id' => 1, 'from_currency' => 'USD', 'to_currency' => 'GBP', 'rate_date' => '2026-01-01', 'rate' => 0.79]);
    $recordRate->handle(['team_id' => 2, 'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-01-02', 'rate' => 1.09]);

    $run = $createReval->handle([
        'team_id' => 1,
        'run_ref' => 'REV-Q1',
        'as_of_date' => '2026-03-31',
        'functional_currency' => 'GBP',
    ], [
        ['reference_id' => 'A1', 'currency' => 'USD', 'foreign_amount' => 100, 'book_rate' => 0.8, 'closing_rate' => 0.82],
    ]);

    expect($query->rates()->total())->toBe(2)
        ->and($query->rates(teamId: 1, from: 'USD', to: 'GBP')->total())->toBe(1)
        ->and($query->rates(perPage: 0)->perPage())->toBe(1)
        ->and($query->rates(perPage: 500)->perPage())->toBe(100)
        ->and($query->revaluations()->total())->toBe(1)
        ->and($query->revaluations(teamId: 1)->total())->toBe(1)
        ->and($query->revaluations(teamId: 99)->total())->toBe(0)
        ->and($query->report($run))->toBe([
            'run_ref' => 'REV-Q1',
            'as_of_date' => '2026-03-31',
            'functional_currency' => 'GBP',
            'status' => 'calculated',
            'summary' => $run->summary,
        ]);
});

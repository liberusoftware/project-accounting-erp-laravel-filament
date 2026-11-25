<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Depreciation\Actions\CreateDepreciationSchedule;
use Liberu\Accounting\Depreciation\Actions\PostDepreciationRun;
use Liberu\Accounting\Depreciation\Actions\RunDepreciation;
use Liberu\Accounting\Depreciation\DepreciationServiceProvider;
use Liberu\Accounting\Depreciation\Enums\DepreciationMethod;
use Liberu\Accounting\Depreciation\Enums\DepreciationRunStatus;
use Liberu\Accounting\Depreciation\Enums\DepreciationScheduleStatus;
use Liberu\Accounting\Depreciation\Exceptions\InvalidDepreciation;
use Liberu\Accounting\Depreciation\Models\DepreciationRun;
use Liberu\Accounting\Depreciation\Models\DepreciationSchedule;
use Liberu\Accounting\Depreciation\Models\DepreciationTaxBook;
use Liberu\Accounting\Depreciation\Queries\DepreciationForecast;

it('registers and boots the depreciation service provider and migrations', function (): void {
    $provider = app()->getProvider(DepreciationServiceProvider::class)
        ?? new DepreciationServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DepreciationServiceProvider::class)
        ->and(Schema::hasTable('accounting_depreciation_tax_books'))->toBeTrue()
        ->and(Schema::hasTable('accounting_depreciation_schedules'))->toBeTrue()
        ->and(Schema::hasTable('accounting_depreciation_runs'))->toBeTrue();
});

it('exposes depreciation enums, tax book model, and exception class', function (): void {
    expect(array_map(static fn (DepreciationMethod $m): string => $m->value, DepreciationMethod::cases()))
        ->toBe(['straight_line', 'declining_balance'])
        ->and(array_map(static fn (DepreciationRunStatus $s): string => $s->value, DepreciationRunStatus::cases()))
        ->toBe(['calculated', 'posted'])
        ->and(array_map(static fn (DepreciationScheduleStatus $s): string => $s->value, DepreciationScheduleStatus::cases()))
        ->toBe(['draft', 'active', 'closed']);

    $exception = new InvalidDepreciation('Invalid depreciation state.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid depreciation state.');

    $book = DepreciationTaxBook::create([
        'team_id' => 10,
        'book_ref' => 'UK-GAAP',
        'name' => 'UK GAAP Statutory',
        'jurisdiction' => 'GB',
        'metadata' => ['convention' => 'full_month'],
    ]);
    expect($book)->toBeInstanceOf(DepreciationTaxBook::class)
        ->and($book->metadata)->toBe(['convention' => 'full_month']);
});

it('creates depreciation schedules and rejects invalid parameters', function (): void {
    $action = app(CreateDepreciationSchedule::class);

    $schedule = $action->handle([
        'team_id' => 10,
        'asset_ref' => 'ASSET-100',
        'book_ref' => 'STAT',
        'method' => DepreciationMethod::StraightLine,
        'useful_life_months' => 12,
        'cost' => 1200.00,
        'residual_value' => 120.00,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'currency' => 'GBP',
        'metadata' => ['department' => 'ops'],
    ]);

    expect($schedule)->toBeInstanceOf(DepreciationSchedule::class)
        ->and($schedule->method)->toBe(DepreciationMethod::StraightLine)
        ->and($schedule->status)->toBe(DepreciationScheduleStatus::Active)
        ->and($schedule->useful_life_months)->toBe(12)
        ->and((float) $schedule->cost)->toBe(1200.0)
        ->and((float) $schedule->residual_value)->toBe(120.0)
        ->and($schedule->start_date->toDateString())->toBe('2026-01-01')
        ->and($schedule->end_date?->toDateString())->toBe('2026-12-31')
        ->and($schedule->metadata)->toBe(['department' => 'ops']);
});

it('rejects invalid depreciation schedule inputs', function (array $override): void {
    $base = [
        'team_id' => 1,
        'asset_ref' => 'AST-1',
        'book_ref' => 'IFRS',
        'method' => 'straight_line',
        'useful_life_months' => 24,
        'cost' => 2400,
        'residual_value' => 0,
        'start_date' => '2026-01-01',
        'currency' => 'USD',
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateDepreciationSchedule::class)->handle($payload))
        ->toThrow(InvalidDepreciation::class, 'A valid tenant, asset, book, method, useful life, value and start date are required.');
})->with([
    'missing team_id' => [['team_id' => null]],
    'blank asset_ref' => [['asset_ref' => '']],
    'blank book_ref' => [['book_ref' => ' ']],
    'invalid method' => [['method' => 'sum_of_years']],
    'zero useful_life_months' => [['useful_life_months' => 0]],
    'negative cost' => [['cost' => -1]],
    'negative residual_value' => [['residual_value' => -10]],
    'residual exceeds cost' => [['cost' => 500, 'residual_value' => 600]],
    'blank start_date' => [['start_date' => '']],
    'blank currency' => [['currency' => '']],
]);

it('runs straight-line and declining-balance depreciation and posts calculated runs', function (): void {
    Carbon::setTestNow('2026-08-29 16:00:00');

    $create = app(CreateDepreciationSchedule::class);
    $runAction = app(RunDepreciation::class);
    $postAction = app(PostDepreciationRun::class);

    $slSchedule = $create->handle([
        'team_id' => 20,
        'asset_ref' => 'LAPTOP-1',
        'book_ref' => 'IFRS',
        'method' => DepreciationMethod::StraightLine,
        'useful_life_months' => 10,
        'cost' => 1100.00,
        'residual_value' => 100.00,
        'start_date' => '2026-01-01',
        'currency' => 'USD',
    ]);

    // End before start throws.
    expect(fn () => $runAction->handle($slSchedule, '2026-03-01', '2026-02-01'))
        ->toThrow(InvalidDepreciation::class, 'An active schedule and ordered run period are required.');

    // Inactive schedule throws.
    $draftSchedule = DepreciationSchedule::create([
        'team_id' => 20,
        'asset_ref' => 'LAPTOP-DRAFT',
        'book_ref' => 'IFRS',
        'method' => DepreciationMethod::StraightLine,
        'useful_life_months' => 10,
        'cost' => 1000,
        'residual_value' => 0,
        'start_date' => '2026-01-01',
        'currency' => 'USD',
        'status' => DepreciationScheduleStatus::Draft,
    ]);
    expect(fn () => $runAction->handle($draftSchedule, '2026-01-01', '2026-02-01'))
        ->toThrow(InvalidDepreciation::class, 'An active schedule and ordered run period are required.');

    // Straight-line 3-month run: (1000 / 10) * 3 = 300
    $run1 = $runAction->handle($slSchedule, '2026-01-01', '2026-04-01');
    expect($run1)->toBeInstanceOf(DepreciationRun::class)
        ->and((float) $run1->amount)->toBe(300.0)
        ->and((float) $run1->accumulated_amount)->toBe(300.0)
        ->and($run1->status)->toBe(DepreciationRunStatus::Calculated)
        ->and($run1->period_start->toDateString())->toBe('2026-01-01')
        ->and($run1->period_end->toDateString())->toBe('2026-04-01')
        ->and($run1->schedule->is($slSchedule))->toBeTrue();

    // Duplicate period throws.
    expect(fn () => $runAction->handle($slSchedule, '2026-01-01', '2026-04-01'))
        ->toThrow(InvalidDepreciation::class, 'A depreciation run already exists for this period.');

    // Capping at remaining depreciable base (700 remaining, asking 9 months -> capped at 700).
    $run2 = $runAction->handle($slSchedule, '2026-04-01', '2027-01-01');
    expect((float) $run2->amount)->toBe(700.0)
        ->and((float) $run2->accumulated_amount)->toBe(1000.0);

    // Declining-balance 1-month run: cost=1000, useful_life=12 -> 1000 * (1 - 0.5^(1/12)) ≈ 56.13
    $dbSchedule = $create->handle([
        'team_id' => 20,
        'asset_ref' => 'SERVER-1',
        'book_ref' => 'TAX',
        'method' => 'declining_balance',
        'useful_life_months' => 12,
        'cost' => 1000.00,
        'residual_value' => 100.00,
        'start_date' => '2026-01-01',
        'currency' => 'USD',
    ]);
    $dbRun = $runAction->handle($dbSchedule, '2026-01-01', '2026-02-01');
    $expectedDbAmount = round(1000.0 * (1 - pow(0.5, 1 / 12)), 2);
    expect((float) $dbRun->amount)->toBe($expectedDbAmount)
        ->and((float) $dbRun->accumulated_amount)->toBe($expectedDbAmount);

    // Post validation & happy path
    expect(fn () => $postAction->handle($run1, 5, '  '))
        ->toThrow(InvalidDepreciation::class, 'Only calculated runs with a journal reference can be posted.');

    $posted = $postAction->handle($run1, 5, 'JRN-DEP-2026-Q1');
    expect($posted->status)->toBe(DepreciationRunStatus::Posted)
        ->and($posted->posted_by)->toBe(5)
        ->and($posted->posted_at?->toDateTimeString())->toBe('2026-08-29 16:00:00')
        ->and($posted->journal_ref)->toBe('JRN-DEP-2026-Q1')
        ->and($posted->relationLoaded('schedule'))->toBeTrue();

    expect(fn () => $postAction->handle($posted, 5, 'JRN-DEP-2026-Q1-DUP'))
        ->toThrow(InvalidDepreciation::class);

    Carbon::setTestNow();
});

it('forecasts depreciation summary per team', function (): void {
    $forecast = app(DepreciationForecast::class);

    expect($forecast->forTeam(999))->toBeEmpty();

    $create = app(CreateDepreciationSchedule::class);
    $run = app(RunDepreciation::class);

    $s1 = $create->handle([
        'team_id' => 30,
        'asset_ref' => 'VAN-1',
        'book_ref' => 'IFRS',
        'method' => DepreciationMethod::StraightLine,
        'useful_life_months' => 12,
        'cost' => 1300.00,
        'residual_value' => 100.00,
        'start_date' => '2026-01-01',
        'currency' => 'GBP',
    ]);
    $run->handle($s1, '2026-01-01', '2026-04-01');

    $s2 = $create->handle([
        'team_id' => 31,
        'asset_ref' => 'VAN-2',
        'book_ref' => 'IFRS',
        'method' => DepreciationMethod::StraightLine,
        'useful_life_months' => 12,
        'cost' => 500.00,
        'residual_value' => 0,
        'start_date' => '2026-01-01',
        'currency' => 'GBP',
    ]);

    $rows = $forecast->forTeam(30);

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toBe([
            'schedule_id' => $s1->id,
            'asset_ref' => 'VAN-1',
            'book_ref' => 'IFRS',
            'method' => 'straight_line',
            'cost' => 1300.0,
            'residual_value' => 100.0,
            'depreciated' => 300.0,
            'remaining' => 900.0,
        ])
        ->and($forecast->forTeam(31)->first()['depreciated'])->toBe(0.0);
});

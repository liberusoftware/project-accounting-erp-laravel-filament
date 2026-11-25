<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\Forecasts\Actions\AddForecastAssumption;
use Liberu\Accounting\Forecasts\Actions\AddForecastLine;
use Liberu\Accounting\Forecasts\Actions\CreateForecast;
use Liberu\Accounting\Forecasts\Actions\CreateForecastPeriods;
use Liberu\Accounting\Forecasts\Actions\DecideForecast;
use Liberu\Accounting\Forecasts\Actions\ReplaceActual;
use Liberu\Accounting\Forecasts\Actions\SubmitForecast;
use Liberu\Accounting\Forecasts\Enums\ForecastMethod;
use Liberu\Accounting\Forecasts\Enums\ForecastStatus;
use Liberu\Accounting\Forecasts\Events\ForecastApproved;
use Liberu\Accounting\Forecasts\Events\ForecastSubmitted;
use Liberu\Accounting\Forecasts\Exceptions\InvalidForecast;
use Liberu\Accounting\Forecasts\ForecastsServiceProvider;
use Liberu\Accounting\Forecasts\Models\ForecastActual;
use Liberu\Accounting\Forecasts\Models\ForecastApproval;
use Liberu\Accounting\Forecasts\Models\ForecastAssumption;
use Liberu\Accounting\Forecasts\Models\ForecastLine;
use Liberu\Accounting\Forecasts\Models\ForecastPeriod;
use Liberu\Accounting\Forecasts\Queries\ForecastQuery;

it('boots forecasts service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(ForecastsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(ForecastsServiceProvider::class)
        ->and(array_column(ForecastMethod::cases(), 'value'))->toBe(['driver', 'manual'])
        ->and(array_column(ForecastStatus::cases(), 'value'))->toBe(['draft', 'submitted', 'approved', 'archived'])
        ->and((new InvalidForecast('Forecast error'))->getMessage())->toBe('Forecast error');
});

it('executes full forecast lifecycle, relations, events, actuals, and queries', function (): void {
    Event::fake([ForecastSubmitted::class, ForecastApproved::class]);

    $forecast = app(CreateForecast::class)->handle([
        'team_id' => 15,
        'forecast_ref' => 'FC-2026-Q1',
        'name' => 'Q1 Rolling Forecast',
        'currency' => 'eur',
        'method' => 'driver',
        'base_period' => '2026-01',
        'horizon_periods' => 3,
        'scenario_ref' => 'BASE',
        'metadata' => ['owner' => 'fp&a'],
    ]);

    expect($forecast->status)->toBe(ForecastStatus::Draft)
        ->and($forecast->method)->toBe(ForecastMethod::Driver)
        ->and($forecast->currency)->toBe('EUR')
        ->and($forecast->horizon_periods)->toBe(3);

    $periods = app(CreateForecastPeriods::class)->handle($forecast);
    expect($periods)->toHaveCount(3)
        ->and($periods[0])->toBeInstanceOf(ForecastPeriod::class)
        ->and($periods[0]->period_ref)->toBe('2026-01')
        ->and($periods[0]->forecast->is($forecast))->toBeTrue()
        ->and($forecast->periods()->count())->toBe(3);

    $assumption = app(AddForecastAssumption::class)->handle($forecast, [
        'assumption_ref' => 'GROWTH-RATE',
        'name' => 'Monthly Revenue Growth',
        'value' => 0.0525,
        'unit' => 'ratio',
        'source' => 'board-plan',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'metadata' => ['confidence' => 'high'],
    ]);

    expect($assumption)->toBeInstanceOf(ForecastAssumption::class)
        ->and($assumption->forecast->is($forecast))->toBeTrue()
        ->and($forecast->assumptions()->count())->toBe(1);

    $line = app(AddForecastLine::class)->handle($forecast, [
        'period_ref' => '2026-01',
        'account_ref' => '4000',
        'dimension_ref' => 'EMEA',
        'description' => 'Subscription revenue',
        'driver_ref' => 'GROWTH-RATE',
        'forecast_value' => 100000.00,
        'metadata' => ['note' => 'baseline'],
    ]);

    expect($line)->toBeInstanceOf(ForecastLine::class)
        ->and($line->forecast->is($forecast))->toBeTrue()
        ->and((float) $line->forecast_value)->toBe(100000.0);

    // Submit then reject back to draft
    $submitted = app(SubmitForecast::class)->handle($forecast);
    expect($submitted->status)->toBe(ForecastStatus::Submitted);
    Event::assertDispatched(ForecastSubmitted::class, fn (ForecastSubmitted $event): bool => $event->forecast->is($submitted));

    $rejected = app(DecideForecast::class)->handle($submitted, 'cfo@example.test', false, 'Needs revised churn assumption');
    expect($rejected->status)->toBe(ForecastStatus::Draft)
        ->and($rejected->approvals()->count())->toBe(1);
    Event::assertNotDispatched(ForecastApproved::class);

    // Resubmit and approve
    $resubmitted = app(SubmitForecast::class)->handle($rejected);
    $approved = app(DecideForecast::class)->handle($resubmitted, 'cfo@example.test', true, 'Approved');
    expect($approved->status)->toBe(ForecastStatus::Approved)
        ->and($approved->approvals()->count())->toBe(2);

    /** @var ForecastApproval $approval */
    $approval = $approved->approvals()->latest('id')->firstOrFail();
    expect($approval->forecast->is($approved))->toBeTrue()
        ->and($approval->approved)->toBeTrue();
    Event::assertDispatched(ForecastApproved::class, fn (ForecastApproved $event): bool => $event->forecast->is($approved) && $event->actorReference === 'cfo@example.test');

    // Replace actual with matching line and without line
    $actualWithLine = app(ReplaceActual::class)->handle($approved, [
        'line_id' => $line->getKey(),
        'period_ref' => '2026-01',
        'actual_value' => 92500.25,
        'source_ref' => 'gl:2026-01',
        'metadata' => ['closed' => true],
    ]);

    expect($actualWithLine)->toBeInstanceOf(ForecastActual::class)
        ->and($actualWithLine->forecast->is($approved))->toBeTrue()
        ->and($actualWithLine->line->is($line))->toBeTrue()
        ->and((float) $line->refresh()->actual_value)->toBe(92500.25)
        ->and((float) $line->variance_value)->toBe(7499.75);

    $actualWithoutLine = app(ReplaceActual::class)->handle($approved, [
        'period_ref' => '2026-02',
        'actual_value' => 0,
        'source_ref' => 'gl:2026-02',
    ]);
    expect($actualWithoutLine->line_id)->toBeNull()
        ->and($approved->actuals()->count())->toBe(2);

    $query = app(ForecastQuery::class);
    $page = $query->paginate(15, ForecastStatus::Approved, 200);
    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100);

    $minPage = $query->paginate(null, null, 0);
    expect($minPage->total())->toBe(1)
        ->and($minPage->perPage())->toBe(1);

    $variance = $query->variance($approved);
    expect($variance['forecast_total'])->toBe(100000.0)
        ->and($variance['actual_total'])->toBe(92500.25)
        ->and($variance['variance_total'])->toBe(7499.75)
        ->and($variance['lines'])->toHaveCount(1)
        ->and($variance['lines'][0]['period_ref'])->toBe('2026-01')
        ->and($variance['lines'][0]['account_ref'])->toBe('4000');
});

it('enforces validation and state transitions across forecast actions', function (): void {
    $create = app(CreateForecast::class);
    $validForecast = [
        'team_id' => 16,
        'forecast_ref' => 'FC-ERR',
        'name' => 'Validation Forecast',
        'currency' => 'USD',
        'method' => 'manual',
        'base_period' => '2026-03',
        'horizon_periods' => 2,
    ];

    foreach (['forecast_ref', 'name', 'currency', 'base_period', 'horizon_periods'] as $field) {
        expect(fn () => $create->handle(array_diff_key($validForecast, [$field => true])))
            ->toThrow(InvalidForecast::class, "Missing forecast field [{$field}].");
    }

    expect(fn () => $create->handle([...$validForecast, 'method' => 'unknown']))
        ->toThrow(InvalidForecast::class, 'Forecast method and horizon must be valid.')
        ->and(fn () => $create->handle([...$validForecast, 'horizon_periods' => 0]))
        ->toThrow(InvalidForecast::class, 'Forecast method and horizon must be valid.');

    $forecast = $create->handle($validForecast);

    // AddForecastAssumption validations
    $addAssumption = app(AddForecastAssumption::class);
    $validAssumption = [
        'assumption_ref' => 'A-1',
        'name' => 'FX Rate',
        'unit' => 'ratio',
        'source' => 'ecb',
        'effective_from' => '2026-03-01',
    ];
    foreach (['assumption_ref', 'name', 'unit', 'source', 'effective_from'] as $field) {
        expect(fn () => $addAssumption->handle($forecast, array_diff_key($validAssumption, [$field => true])))
            ->toThrow(InvalidForecast::class, "Missing assumption field [{$field}].");
    }
    expect(fn () => $addAssumption->handle($forecast, [...$validAssumption, 'effective_to' => '2026-02-01']))
        ->toThrow(InvalidForecast::class, 'An assumption cannot end before it becomes effective.');

    // AddForecastLine validations
    $addLine = app(AddForecastLine::class);
    $validLine = ['period_ref' => '2026-03', 'account_ref' => '5000', 'description' => 'COGS', 'forecast_value' => 100];
    foreach (['period_ref', 'account_ref', 'description'] as $field) {
        expect(fn () => $addLine->handle($forecast, array_diff_key($validLine, [$field => true])))
            ->toThrow(InvalidForecast::class, "Missing line field [{$field}].");
    }

    // Submit without lines, decide on draft
    expect(fn () => app(SubmitForecast::class)->handle($forecast))
        ->toThrow(InvalidForecast::class, 'Only draft forecasts with lines can be submitted.')
        ->and(fn () => app(DecideForecast::class)->handle($forecast, 'cfo', true))
        ->toThrow(InvalidForecast::class, 'Only submitted forecasts can be approved.');

    $addLine->handle($forecast, $validLine);
    $submitted = app(SubmitForecast::class)->handle($forecast);

    // Non-draft guards
    expect(fn () => app(CreateForecastPeriods::class)->handle($submitted))
        ->toThrow(InvalidForecast::class, 'Only draft forecasts can have periods generated.')
        ->and(fn () => $addAssumption->handle($submitted, $validAssumption))
        ->toThrow(InvalidForecast::class, 'Only draft forecasts can be changed.')
        ->and(fn () => $addLine->handle($submitted, $validLine))
        ->toThrow(InvalidForecast::class, 'Only draft forecasts can be edited.')
        ->and(fn () => app(SubmitForecast::class)->handle($submitted))
        ->toThrow(InvalidForecast::class, 'Only draft forecasts with lines can be submitted.')
        ->and(fn () => app(DecideForecast::class)->handle($submitted, 'cfo', false, '   '))
        ->toThrow(InvalidForecast::class, 'Rejected forecasts require a comment.');

    // ReplaceActual validations
    $replaceActual = app(ReplaceActual::class);
    expect(fn () => $replaceActual->handle($submitted, ['source_ref' => 'gl', 'actual_value' => 10]))
        ->toThrow(InvalidForecast::class, 'Missing actual field [period_ref].')
        ->and(fn () => $replaceActual->handle($submitted, ['period_ref' => '2026-03', 'actual_value' => 10]))
        ->toThrow(InvalidForecast::class, 'Missing actual field [source_ref].')
        ->and(fn () => $replaceActual->handle($submitted, ['period_ref' => '2026-03', 'source_ref' => 'gl', 'actual_value' => -5]))
        ->toThrow(InvalidForecast::class, 'Actual value cannot be negative.');
});

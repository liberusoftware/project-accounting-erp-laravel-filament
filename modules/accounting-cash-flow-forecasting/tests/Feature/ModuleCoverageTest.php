<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CashFlowForecasting\Actions\AddForecastItem;
use Liberu\Accounting\CashFlowForecasting\Actions\CreateCashFlowForecast;
use Liberu\Accounting\CashFlowForecasting\Actions\SetForecastScenario;
use Liberu\Accounting\CashFlowForecasting\Actions\UpdateCashFlowForecast;
use Liberu\Accounting\CashFlowForecasting\CashFlowForecastingServiceProvider;
use Liberu\Accounting\CashFlowForecasting\Exceptions\InvalidCashFlowForecast;
use Liberu\Accounting\CashFlowForecasting\Models\CashFlowForecast;
use Liberu\Accounting\CashFlowForecasting\Queries\CashFlowForecastQuery;

it('boots the cash flow forecasting service provider and schema', function (): void {
    $provider = app()->getProvider(CashFlowForecastingServiceProvider::class);
    expect($provider)->toBeInstanceOf(CashFlowForecastingServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_cash_flow_forecasts'))->toBeTrue();

    $exception = new InvalidCashFlowForecast('Bad forecast');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad forecast');
});

it('creates a cash flow forecast and validates required and domain constraints', function (): void {
    $create = new CreateCashFlowForecast();

    foreach (['team_id', 'forecast_ref', 'currency', 'starts_on', 'ends_on'] as $field) {
        $payload = [
            'team_id' => 1,
            'forecast_ref' => 'FCF-1',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-31',
        ];
        unset($payload[$field]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidCashFlowForecast::class, "{$field} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 1,
        'forecast_ref' => 'FCF-BAD-CUR',
        'currency' => 'usd',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-03-31',
    ]))->toThrow(InvalidCashFlowForecast::class, 'Forecast dates, currency, or opening cash are invalid.');

    expect(fn () => $create->handle([
        'team_id' => 1,
        'forecast_ref' => 'FCF-BAD-DATE',
        'currency' => 'USD',
        'starts_on' => '2026-04-01',
        'ends_on' => '2026-03-31',
    ]))->toThrow(InvalidCashFlowForecast::class);

    expect(fn () => $create->handle([
        'team_id' => 1,
        'forecast_ref' => 'FCF-BAD-CASH',
        'currency' => 'USD',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-03-31',
        'opening_cash' => -10,
    ]))->toThrow(InvalidCashFlowForecast::class);

    $forecast = $create->handle([
        'team_id' => 1,
        'forecast_ref' => 'FCF-2026-Q1',
        'currency' => 'GBP',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-03-31',
        'opening_cash' => 50000,
        'forecast_cash' => 65000,
        'variance' => 15000,
        'confidence' => 0.875,
        'assumptions' => ['collection_days' => 30],
    ]);

    expect($forecast)->toBeInstanceOf(CashFlowForecast::class)
        ->and($forecast->starts_on)->toBeInstanceOf(Carbon::class)
        ->and($forecast->ends_on)->toBeInstanceOf(Carbon::class)
        ->and($forecast->opening_cash)->toBe('50000.00000000')
        ->and($forecast->confidence)->toBe('0.875000')
        ->and($forecast->assumptions)->toBe(['collection_days' => 30]);
});

it('updates forecast and adds receivables, payables, recurring items, and scenarios', function (): void {
    $forecast = (new CreateCashFlowForecast())->handle([
        'team_id' => 2,
        'forecast_ref' => 'FCF-ITEMS',
        'currency' => 'EUR',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-06-30',
        'opening_cash' => 10000,
    ]);

    $updater = new UpdateCashFlowForecast();
    expect(fn () => $updater->handle($forecast, ['confidence' => -0.1]))
        ->toThrow(InvalidCashFlowForecast::class, 'Confidence must be between zero and one.');
    expect(fn () => $updater->handle($forecast, ['confidence' => 1.1]))
        ->toThrow(InvalidCashFlowForecast::class, 'Confidence must be between zero and one.');

    $forecast = $updater->handle($forecast, ['confidence' => 0.95, 'forecast_cash' => 18000]);
    expect($forecast->confidence)->toBe('0.950000')
        ->and($forecast->forecast_cash)->toBe('18000.00000000');

    $items = new AddForecastItem();
    expect(fn () => $items->receivable($forecast, ['amount' => 100]))
        ->toThrow(InvalidCashFlowForecast::class, 'Forecast item timing and non-negative amount are required.');
    expect(fn () => $items->payable($forecast, ['due_on' => '2026-02-01']))
        ->toThrow(InvalidCashFlowForecast::class);
    expect(fn () => $items->recurring($forecast, ['frequency' => 'monthly', 'amount' => -1]))
        ->toThrow(InvalidCashFlowForecast::class);

    $forecast = $items->receivable($forecast, ['due_on' => '2026-02-15', 'amount' => 5000, 'ref' => 'AR-1']);
    $forecast = $items->payable($forecast, ['due_on' => '2026-02-20', 'amount' => 2000, 'ref' => 'AP-1']);
    $forecast = $items->recurring($forecast, ['frequency' => 'monthly', 'amount' => 1500, 'ref' => 'RENT']);

    expect($forecast->receivables)->toHaveCount(1)
        ->and($forecast->payables)->toHaveCount(1)
        ->and($forecast->recurring_items)->toHaveCount(1);

    $scenarios = new SetForecastScenario();
    expect(fn () => $scenarios->handle($forecast, ['cash_adjustment' => 1000]))
        ->toThrow(InvalidCashFlowForecast::class, 'Scenario name and cash adjustment are required.');
    expect(fn () => $scenarios->handle($forecast, ['name' => 'Best case']))
        ->toThrow(InvalidCashFlowForecast::class);

    $forecast = $scenarios->handle($forecast, ['name' => 'Downside', 'cash_adjustment' => -2500]);
    expect($forecast->scenarios)->toBe([['name' => 'Downside', 'cash_adjustment' => -2500]]);
});

it('queries cash flow forecasts by team', function (): void {
    $query = new CashFlowForecastQuery();
    expect($query->forTeam(777))->toBeEmpty();

    $create = new CreateCashFlowForecast();
    $first = $create->handle(['team_id' => 8, 'forecast_ref' => 'F-1', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
    $second = $create->handle(['team_id' => 8, 'forecast_ref' => 'F-2', 'currency' => 'USD', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']);
    $create->handle(['team_id' => 9, 'forecast_ref' => 'F-3', 'currency' => 'USD', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    $results = $query->forTeam(8);
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});

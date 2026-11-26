<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ForecastsApi\ForecastsApiServiceProvider;

it('covers accounting-forecasts-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ForecastsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ForecastsApiServiceProvider::class);

    $base = '/api/v1/accounting/forecasts';

    $storeRes = $this->postJson($base, [
        'forecast_ref' => 'FC-2026',
        'name' => 'FY2026 Rolling Forecast',
        'currency' => 'USD',
        'method' => 'driver',
        'base_period' => '2026-01',
        'horizon_periods' => 3,
        'scenario_ref' => 'BASE',
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $forecastId = $storeRes->json('data.id');
    expect($forecastId)->not->toBeNull();

    expect($this->getJson("{$base}?status=invalid-status")->status())->toBe(422);
    expect($this->getJson("{$base}?status=draft")->status())->toBeGreaterThanOrEqual(200);
    expect($this->getJson($base)->status())->toBeGreaterThanOrEqual(200);

    expect($this->postJson("{$base}/{$forecastId}/lines", [
        'period_ref' => '2026-01',
        'account_ref' => '4000',
        'description' => 'Subscription revenue',
        'forecast_value' => 10000,
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$forecastId}/assumptions", [
        'assumption_ref' => 'ASM-1',
        'name' => 'Monthly growth rate',
        'value' => 5.0,
        'unit' => 'percent',
        'source' => 'Sales plan',
        'effective_from' => '2026-01-01',
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$forecastId}/periods", [
        'starts_on' => '2026-01-01',
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$forecastId}/submit")->status())->toBe(200);
    expect($this->postJson("{$base}/{$forecastId}/decide", [
        'actor_ref' => 'CFO-1',
        'approved' => true,
        'comment' => 'Approved',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$forecastId}/actuals", [
        'period_ref' => '2026-01',
        'actual_value' => 10500,
        'source_ref' => 'GL-2026-01',
    ])->status())->toBeLessThan(500);

    expect($this->getJson("{$base}/{$forecastId}")->status())->toBe(200);
    expect($this->getJson("{$base}/{$forecastId}/variance")->status())->toBe(200);
});

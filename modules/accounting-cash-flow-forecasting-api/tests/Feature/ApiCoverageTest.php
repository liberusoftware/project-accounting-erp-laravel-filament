<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CashFlowForecastingApi\CashFlowForecastingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-cash-flow-forecasting-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CashFlowForecastingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashFlowForecastingApiServiceProvider::class);

    $this->postJson('/api/v1/accounting/cash-flow-forecasting', [
        'forecast_ref' => 'CFF-2026-Q1',
        'currency' => 'GBP',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-03-31',
        'opening_cash' => 10000,
    ])->assertCreated()
        ->assertJsonPath('data.forecast_ref', 'CFF-2026-Q1');

    $this->getJson('/api/v1/accounting/cash-flow-forecasting')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

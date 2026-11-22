<?php

declare(strict_types=1);

use Liberu\Accounting\CashFlowForecastingLivewire\CashFlowForecastingLivewireServiceProvider;

it('boots accounting-cash-flow-forecasting-livewire service provider', function (): void {
    $provider = $this->app->register(CashFlowForecastingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CashFlowForecastingLivewireServiceProvider::class);
});

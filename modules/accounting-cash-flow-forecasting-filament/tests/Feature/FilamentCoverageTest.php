<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CashFlowForecastingFilament\CashFlowForecastingFilamentPlugin;
use Liberu\Accounting\CashFlowForecastingFilament\CashFlowForecastingFilamentServiceProvider;

it('registers and configures accounting-cash-flow-forecasting-filament filament resources', function (): void {
    $provider = $this->app->register(CashFlowForecastingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashFlowForecastingFilamentServiceProvider::class);
    $plugin = CashFlowForecastingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-cash-flow-forecasting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

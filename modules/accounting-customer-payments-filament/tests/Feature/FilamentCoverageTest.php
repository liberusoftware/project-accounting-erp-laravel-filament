<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CustomerPaymentsFilament\CustomerPaymentsFilamentPlugin;
use Liberu\Accounting\CustomerPaymentsFilament\CustomerPaymentsFilamentServiceProvider;

it('registers and configures accounting-customer-payments-filament filament resources', function (): void {
    $provider = $this->app->register(CustomerPaymentsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPaymentsFilamentServiceProvider::class);
    $plugin = CustomerPaymentsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-customer-payments-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

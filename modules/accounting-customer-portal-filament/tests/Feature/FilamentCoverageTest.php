<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CustomerPortalFilament\CustomerPortalFilamentPlugin;
use Liberu\Accounting\CustomerPortalFilament\CustomerPortalFilamentServiceProvider;

it('registers and configures accounting-customer-portal-filament filament resources', function (): void {
    $provider = $this->app->register(CustomerPortalFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPortalFilamentServiceProvider::class);
    $plugin = CustomerPortalFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-customer-portal-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

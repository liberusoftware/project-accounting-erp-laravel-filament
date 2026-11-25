<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\DashboardsFilament\DashboardsFilamentPlugin;
use Liberu\Accounting\DashboardsFilament\DashboardsFilamentServiceProvider;

it('registers and configures accounting-dashboards-filament filament resources', function (): void {
    $provider = $this->app->register(DashboardsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DashboardsFilamentServiceProvider::class);
    $plugin = DashboardsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-dashboards-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

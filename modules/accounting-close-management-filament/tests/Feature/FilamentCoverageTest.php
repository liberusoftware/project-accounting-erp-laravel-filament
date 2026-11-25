<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CloseManagementFilament\CloseManagementFilamentPlugin;
use Liberu\Accounting\CloseManagementFilament\CloseManagementFilamentServiceProvider;

it('registers and configures accounting-close-management-filament filament resources', function (): void {
    $provider = $this->app->register(CloseManagementFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CloseManagementFilamentServiceProvider::class);
    $plugin = CloseManagementFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-close-management-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

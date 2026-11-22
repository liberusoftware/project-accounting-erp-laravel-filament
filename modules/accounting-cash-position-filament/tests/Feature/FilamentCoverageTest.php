<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CashPositionFilament\CashPositionFilamentPlugin;
use Liberu\Accounting\CashPositionFilament\CashPositionFilamentServiceProvider;

it('registers and configures accounting-cash-position-filament filament resources', function (): void {
    $provider = $this->app->register(CashPositionFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashPositionFilamentServiceProvider::class);
    $plugin = CashPositionFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-cash-position-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

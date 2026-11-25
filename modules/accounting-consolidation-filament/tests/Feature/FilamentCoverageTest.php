<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\ConsolidationFilament\ConsolidationFilamentPlugin;
use Liberu\Accounting\ConsolidationFilament\ConsolidationFilamentServiceProvider;

it('registers and configures accounting-consolidation-filament filament resources', function (): void {
    $provider = $this->app->register(ConsolidationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ConsolidationFilamentServiceProvider::class);
    $plugin = ConsolidationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-consolidation-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

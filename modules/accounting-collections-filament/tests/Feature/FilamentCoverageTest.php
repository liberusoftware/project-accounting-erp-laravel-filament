<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CollectionsFilament\CollectionsFilamentPlugin;
use Liberu\Accounting\CollectionsFilament\CollectionsFilamentServiceProvider;

it('registers and configures accounting-collections-filament filament resources', function (): void {
    $provider = $this->app->register(CollectionsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CollectionsFilamentServiceProvider::class);
    $plugin = CollectionsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-collections-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

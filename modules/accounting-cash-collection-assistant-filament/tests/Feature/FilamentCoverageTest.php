<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CashCollectionAssistantFilament\CashCollectionAssistantFilamentPlugin;
use Liberu\Accounting\CashCollectionAssistantFilament\CashCollectionAssistantFilamentServiceProvider;

it('registers and configures accounting-cash-collection-assistant-filament filament resources', function (): void {
    $provider = $this->app->register(CashCollectionAssistantFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashCollectionAssistantFilamentServiceProvider::class);
    $plugin = CashCollectionAssistantFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-cash-collection-assistant-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

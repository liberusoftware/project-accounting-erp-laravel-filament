<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CorporateCardsFilament\CorporateCardsFilamentPlugin;
use Liberu\Accounting\CorporateCardsFilament\CorporateCardsFilamentServiceProvider;

it('registers and configures accounting-corporate-cards-filament filament resources', function (): void {
    $provider = $this->app->register(CorporateCardsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CorporateCardsFilamentServiceProvider::class);
    $plugin = CorporateCardsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-corporate-cards-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

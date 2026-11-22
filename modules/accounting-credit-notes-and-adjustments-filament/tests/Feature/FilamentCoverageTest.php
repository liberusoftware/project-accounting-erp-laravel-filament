<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CreditNotesAndAdjustmentsFilament\CreditNotesAndAdjustmentsFilamentPlugin;
use Liberu\Accounting\CreditNotesAndAdjustmentsFilament\CreditNotesAndAdjustmentsFilamentServiceProvider;

it('registers and configures accounting-credit-notes-and-adjustments-filament filament resources', function (): void {
    $provider = $this->app->register(CreditNotesAndAdjustmentsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CreditNotesAndAdjustmentsFilamentServiceProvider::class);
    $plugin = CreditNotesAndAdjustmentsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-credit-notes-and-adjustments-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

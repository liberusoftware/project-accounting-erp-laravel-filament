<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CodingSuggestionsFilament\CodingSuggestionsFilamentPlugin;
use Liberu\Accounting\CodingSuggestionsFilament\CodingSuggestionsFilamentServiceProvider;

it('registers and configures accounting-coding-suggestions-filament filament resources', function (): void {
    $provider = $this->app->register(CodingSuggestionsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CodingSuggestionsFilamentServiceProvider::class);
    $plugin = CodingSuggestionsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-coding-suggestions-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

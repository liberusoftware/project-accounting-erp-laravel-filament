<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\ClientCollaborationFilament\ClientCollaborationFilamentPlugin;
use Liberu\Accounting\ClientCollaborationFilament\ClientCollaborationFilamentServiceProvider;

it('registers and configures accounting-client-collaboration-filament filament resources', function (): void {
    $provider = $this->app->register(ClientCollaborationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ClientCollaborationFilamentServiceProvider::class);
    $plugin = ClientCollaborationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-client-collaboration-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

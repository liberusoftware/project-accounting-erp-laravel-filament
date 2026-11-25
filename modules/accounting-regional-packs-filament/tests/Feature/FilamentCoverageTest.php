<?php

declare(strict_types=1);

use Liberu\Accounting\RegionalPacksFilament\RegionalPacksFilamentServiceProvider;

it('registers and configures accounting-regional-packs-filament filament resources', function (): void {
    $provider = $this->app->register(RegionalPacksFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(RegionalPacksFilamentServiceProvider::class);
});

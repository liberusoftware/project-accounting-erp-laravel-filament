<?php

declare(strict_types=1);

use Liberu\Accounting\RegionalPacksLivewire\RegionalPacksLivewireServiceProvider;

it('boots accounting-regional-packs-livewire service provider', function (): void {
    $provider = $this->app->register(RegionalPacksLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(RegionalPacksLivewireServiceProvider::class);
});

<?php

declare(strict_types=1);

use Liberu\Accounting\CashPositionLivewire\CashPositionLivewireServiceProvider;

it('boots accounting-cash-position-livewire service provider', function (): void {
    $provider = $this->app->register(CashPositionLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CashPositionLivewireServiceProvider::class);
});

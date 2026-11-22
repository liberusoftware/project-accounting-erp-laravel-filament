<?php

declare(strict_types=1);

use Liberu\Accounting\ConsolidationLivewire\ConsolidationLivewireServiceProvider;

it('boots accounting-consolidation-livewire service provider', function (): void {
    $provider = $this->app->register(ConsolidationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(ConsolidationLivewireServiceProvider::class);
});

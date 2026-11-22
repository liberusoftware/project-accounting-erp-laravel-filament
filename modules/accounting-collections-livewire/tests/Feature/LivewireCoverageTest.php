<?php

declare(strict_types=1);

use Liberu\Accounting\CollectionsLivewire\CollectionsLivewireServiceProvider;

it('boots accounting-collections-livewire service provider', function (): void {
    $provider = $this->app->register(CollectionsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CollectionsLivewireServiceProvider::class);
});

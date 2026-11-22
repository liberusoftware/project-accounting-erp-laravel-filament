<?php

declare(strict_types=1);

use Liberu\Accounting\QuickBooksOnlineMigrationLivewire\QuickBooksOnlineMigrationLivewireServiceProvider;

it('boots accounting-quickbooks-online-migration-livewire service provider', function (): void {
    $provider = $this->app->register(QuickBooksOnlineMigrationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(QuickBooksOnlineMigrationLivewireServiceProvider::class);
});

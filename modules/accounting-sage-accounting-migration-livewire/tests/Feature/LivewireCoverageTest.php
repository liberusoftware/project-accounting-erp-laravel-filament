<?php

declare(strict_types=1);

use Liberu\Accounting\SageAccountingMigrationLivewire\SageAccountingMigrationLivewireServiceProvider;

it('boots accounting-sage-accounting-migration-livewire service provider', function (): void {
    $provider = $this->app->register(SageAccountingMigrationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(SageAccountingMigrationLivewireServiceProvider::class);
});

<?php

declare(strict_types=1);

use Liberu\Accounting\SageAccountingMigrationFilament\SageAccountingMigrationFilamentServiceProvider;

it('registers and configures accounting-sage-accounting-migration-filament filament resources', function (): void {
    $provider = $this->app->register(SageAccountingMigrationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SageAccountingMigrationFilamentServiceProvider::class);
});

<?php

declare(strict_types=1);

use Liberu\Accounting\QuickBooksOnlineMigrationFilament\QuickBooksOnlineMigrationFilamentServiceProvider;

it('registers and configures accounting-quickbooks-online-migration-filament filament resources', function (): void {
    $provider = $this->app->register(QuickBooksOnlineMigrationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(QuickBooksOnlineMigrationFilamentServiceProvider::class);
});

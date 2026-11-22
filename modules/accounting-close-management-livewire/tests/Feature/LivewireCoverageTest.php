<?php

declare(strict_types=1);

use Liberu\Accounting\CloseManagementLivewire\CloseManagementLivewireServiceProvider;

it('boots accounting-close-management-livewire service provider', function (): void {
    $provider = $this->app->register(CloseManagementLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CloseManagementLivewireServiceProvider::class);
});

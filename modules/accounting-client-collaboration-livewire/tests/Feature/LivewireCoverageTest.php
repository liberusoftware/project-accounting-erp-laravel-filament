<?php

declare(strict_types=1);

use Liberu\Accounting\ClientCollaborationLivewire\ClientCollaborationLivewireServiceProvider;

it('boots accounting-client-collaboration-livewire service provider', function (): void {
    $provider = $this->app->register(ClientCollaborationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(ClientCollaborationLivewireServiceProvider::class);
});

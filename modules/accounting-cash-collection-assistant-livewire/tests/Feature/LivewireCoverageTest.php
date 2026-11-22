<?php

declare(strict_types=1);

use Liberu\Accounting\CashCollectionAssistantLivewire\CashCollectionAssistantLivewireServiceProvider;

it('boots accounting-cash-collection-assistant-livewire service provider', function (): void {
    $provider = $this->app->register(CashCollectionAssistantLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CashCollectionAssistantLivewireServiceProvider::class);
});

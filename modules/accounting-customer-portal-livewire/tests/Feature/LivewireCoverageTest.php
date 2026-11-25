<?php

declare(strict_types=1);

use Liberu\Accounting\CustomerPortalLivewire\CustomerPortalLivewireServiceProvider;
use Liberu\Accounting\CustomerPortalLivewire\Livewire\PortalOverview;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-customer-portal-livewire livewire components', function (): void {
    $provider = $this->app->register(CustomerPortalLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPortalLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new PortalOverview();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

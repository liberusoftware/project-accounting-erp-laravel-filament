<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SupplierPortalLivewire\Livewire\PortalResources;
use Liberu\Accounting\SupplierPortalLivewire\SupplierPortalLivewireServiceProvider;

it('renders accounting-supplier-portal-livewire livewire components', function (): void {
    $provider = $this->app->register(SupplierPortalLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SupplierPortalLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new PortalResources();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

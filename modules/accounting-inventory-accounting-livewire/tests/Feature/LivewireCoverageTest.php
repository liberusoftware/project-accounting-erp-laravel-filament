<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\InventoryAccountingLivewire\InventoryAccountingLivewireServiceProvider;
use Liberu\Accounting\InventoryAccountingLivewire\Livewire\InventoryAccounting;

it('renders accounting-inventory-accounting-livewire livewire components', function (): void {
    $provider = $this->app->register(InventoryAccountingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(InventoryAccountingLivewireServiceProvider::class);

    expect(fn () => (new InventoryAccounting())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new InventoryAccounting();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});

<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProductAndServiceItemsLivewire\Livewire\AccountingItems;
use Liberu\Accounting\ProductAndServiceItemsLivewire\ProductAndServiceItemsLivewireServiceProvider;

it('renders accounting-product-and-service-items-livewire livewire components', function (): void {
    $provider = $this->app->register(ProductAndServiceItemsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProductAndServiceItemsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $component = new AccountingItems();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();

    $component->search = 'widget';
    expect($component->render())->not->toBeNull();
});

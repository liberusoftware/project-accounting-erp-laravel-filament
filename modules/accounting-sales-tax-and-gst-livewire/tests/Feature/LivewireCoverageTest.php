<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SalesTaxAndGstLivewire\Livewire\SalesTaxRecords;
use Liberu\Accounting\SalesTaxAndGstLivewire\SalesTaxAndGstLivewireServiceProvider;

it('renders accounting-sales-tax-and-gst-livewire livewire components', function (): void {
    $provider = $this->app->register(SalesTaxAndGstLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SalesTaxAndGstLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new SalesTaxRecords();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

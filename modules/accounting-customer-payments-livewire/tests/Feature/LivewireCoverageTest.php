<?php

declare(strict_types=1);

use Liberu\Accounting\CustomerPaymentsLivewire\CustomerPaymentsLivewireServiceProvider;
use Liberu\Accounting\CustomerPaymentsLivewire\Livewire\PaymentOverview;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-customer-payments-livewire livewire components', function (): void {
    $provider = $this->app->register(CustomerPaymentsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPaymentsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new PaymentOverview();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

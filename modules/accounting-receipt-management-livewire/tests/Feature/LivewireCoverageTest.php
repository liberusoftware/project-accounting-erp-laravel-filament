<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReceiptManagementLivewire\Livewire\Receipts;
use Liberu\Accounting\ReceiptManagementLivewire\ReceiptManagementLivewireServiceProvider;

it('renders accounting-receipt-management-livewire livewire components', function (): void {
    $provider = $this->app->register(ReceiptManagementLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ReceiptManagementLivewireServiceProvider::class);

    expect(fn () => (new Receipts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Receipts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

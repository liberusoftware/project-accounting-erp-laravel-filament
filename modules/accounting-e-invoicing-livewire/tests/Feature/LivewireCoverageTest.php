<?php

declare(strict_types=1);

use Liberu\Accounting\EInvoicingLivewire\EInvoicingLivewireServiceProvider;
use Liberu\Accounting\EInvoicingLivewire\Livewire\Documents;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-e-invoicing-livewire livewire components', function (): void {
    $provider = $this->app->register(EInvoicingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EInvoicingLivewireServiceProvider::class);

    expect(fn () => (new Documents())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Documents();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
    expect($component->render())->not->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SalesInvoicingLivewire\Livewire\Invoices;
use Liberu\Accounting\SalesInvoicingLivewire\SalesInvoicingLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-sales-invoicing-livewire livewire components', function (): void {
    $provider = $this->app->register(SalesInvoicingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(SalesInvoicingLivewireServiceProvider::class);


    $this->actingAs(User::factory()->create());

    $component = new Invoices;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});

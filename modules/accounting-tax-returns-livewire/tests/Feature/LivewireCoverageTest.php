<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TaxReturnsLivewire\Livewire\TaxReturns;
use Liberu\Accounting\TaxReturnsLivewire\TaxReturnsLivewireServiceProvider;

it('renders accounting-tax-returns-livewire livewire components', function (): void {
    $provider = $this->app->register(TaxReturnsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TaxReturnsLivewireServiceProvider::class);

    expect(fn () => (new TaxReturns())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new TaxReturns();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\LeasesLivewire\LeasesLivewireServiceProvider;
use Liberu\Accounting\LeasesLivewire\Livewire\Leases;

it('renders accounting-leases-livewire livewire components', function (): void {
    $provider = $this->app->register(LeasesLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(LeasesLivewireServiceProvider::class);

    expect(fn () => (new Leases())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Leases();
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

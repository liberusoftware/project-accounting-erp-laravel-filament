<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FixedAssetsLivewire\FixedAssetsLivewireServiceProvider;
use Liberu\Accounting\FixedAssetsLivewire\Livewire\Assets;

it('renders accounting-fixed-assets-livewire livewire components', function (): void {
    $provider = $this->app->register(FixedAssetsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(FixedAssetsLivewireServiceProvider::class);

    expect(fn () => (new Assets())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Assets();
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

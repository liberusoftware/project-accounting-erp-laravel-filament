<?php

declare(strict_types=1);

use Liberu\Accounting\DimensionsLivewire\DimensionsLivewireServiceProvider;
use Liberu\Accounting\DimensionsLivewire\Livewire\Dimensions;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-dimensions-and-tracking-livewire livewire components', function (): void {
    $provider = $this->app->register(DimensionsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DimensionsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new Dimensions();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

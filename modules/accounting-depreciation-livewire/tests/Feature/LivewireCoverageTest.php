<?php

declare(strict_types=1);

use Liberu\Accounting\DepreciationLivewire\DepreciationLivewireServiceProvider;
use Liberu\Accounting\DepreciationLivewire\Livewire\DepreciationSchedules;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-depreciation-livewire livewire components', function (): void {
    $provider = $this->app->register(DepreciationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DepreciationLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new DepreciationSchedules();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

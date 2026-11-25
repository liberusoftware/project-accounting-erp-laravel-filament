<?php

declare(strict_types=1);

use Liberu\Accounting\BranchLocationAccountingLivewire\BranchLocationAccountingLivewireServiceProvider;
use Liberu\Accounting\BranchLocationAccountingLivewire\Livewire\Branches;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-branch-and-location-accounting-livewire livewire components', function (): void {
    $provider = $this->app->register(BranchLocationAccountingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BranchLocationAccountingLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new Branches();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

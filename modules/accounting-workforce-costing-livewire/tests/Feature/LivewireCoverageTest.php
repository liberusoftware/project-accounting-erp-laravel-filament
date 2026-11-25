<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WorkforceCostingLivewire\Livewire\WorkforceCosts;
use Liberu\Accounting\WorkforceCostingLivewire\WorkforceCostingLivewireServiceProvider;

it('renders accounting-workforce-costing-livewire livewire components', function (): void {
    $provider = $this->app->register(WorkforceCostingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(WorkforceCostingLivewireServiceProvider::class);

    expect(fn () => (new WorkforceCosts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new WorkforceCosts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

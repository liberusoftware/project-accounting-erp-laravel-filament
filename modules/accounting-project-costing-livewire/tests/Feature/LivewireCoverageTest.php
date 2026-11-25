<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectCostingLivewire\Livewire\ProjectCosts;
use Liberu\Accounting\ProjectCostingLivewire\ProjectCostingLivewireServiceProvider;

it('renders accounting-project-costing-livewire livewire components', function (): void {
    $provider = $this->app->register(ProjectCostingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectCostingLivewireServiceProvider::class);

    expect(fn () => (new ProjectCosts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new ProjectCosts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

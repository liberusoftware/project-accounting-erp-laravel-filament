<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectProfitabilityLivewire\Livewire\ProjectProfitability;
use Liberu\Accounting\ProjectProfitabilityLivewire\ProjectProfitabilityLivewireServiceProvider;

it('renders accounting-project-profitability-livewire livewire components', function (): void {
    $provider = $this->app->register(ProjectProfitabilityLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectProfitabilityLivewireServiceProvider::class);

    expect(fn () => (new ProjectProfitability())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new ProjectProfitability();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

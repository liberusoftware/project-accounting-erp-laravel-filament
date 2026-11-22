<?php

declare(strict_types=1);

use Liberu\Accounting\DashboardsLivewire\DashboardsLivewireServiceProvider;
use Liberu\Accounting\DashboardsLivewire\Livewire\DashboardOverview;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-dashboards-livewire livewire components', function (): void {
    $provider = $this->app->register(DashboardsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DashboardsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new DashboardOverview();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

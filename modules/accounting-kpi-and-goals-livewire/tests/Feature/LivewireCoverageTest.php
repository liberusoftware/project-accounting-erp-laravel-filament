<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\KpiAndGoalsLivewire\KpiAndGoalsLivewireServiceProvider;
use Liberu\Accounting\KpiAndGoalsLivewire\Livewire\Goals;

it('renders accounting-kpi-and-goals-livewire livewire components', function (): void {
    $provider = $this->app->register(KpiAndGoalsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(KpiAndGoalsLivewireServiceProvider::class);

    expect(fn () => (new Goals())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Goals();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});

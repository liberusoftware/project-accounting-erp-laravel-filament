<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TimeTrackingLivewire\Livewire\TimeEntries;
use Liberu\Accounting\TimeTrackingLivewire\TimeTrackingLivewireServiceProvider;

it('renders accounting-time-tracking-livewire livewire components', function (): void {
    $provider = $this->app->register(TimeTrackingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TimeTrackingLivewireServiceProvider::class);

    expect(fn () => (new TimeEntries())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new TimeEntries();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ThreeWayMatchingLivewire\Livewire\Matches;
use Liberu\Accounting\ThreeWayMatchingLivewire\ThreeWayMatchingLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-three-way-matching-livewire livewire components', function (): void {
    $provider = $this->app->register(ThreeWayMatchingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(ThreeWayMatchingLivewireServiceProvider::class);

    expect(fn () => (new Matches)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Matches;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WorkpapersLivewire\Livewire\Workpapers;
use Liberu\Accounting\WorkpapersLivewire\WorkpapersLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-workpapers-livewire livewire components', function (): void {
    $provider = $this->app->register(WorkpapersLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(WorkpapersLivewireServiceProvider::class);

    expect(fn () => (new Workpapers)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Workpapers;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});

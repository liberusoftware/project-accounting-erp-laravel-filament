<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReviewLivewire\Livewire\ReviewItems;
use Liberu\Accounting\ReviewLivewire\ReviewLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-review-livewire livewire components', function (): void {
    $provider = $this->app->register(ReviewLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(ReviewLivewireServiceProvider::class);


    $this->actingAs(User::factory()->create());

    $component = new ReviewItems;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});

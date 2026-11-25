<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MultiEntityLivewire\Livewire\EntityBooks;
use Liberu\Accounting\MultiEntityLivewire\MultiEntityLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-multi-entity-livewire livewire components', function (): void {
    $provider = $this->app->register(MultiEntityLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MultiEntityLivewireServiceProvider::class);

    expect(fn () => (new EntityBooks())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(EntityBooks::class)->assertOk();
});

<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TransfersLivewire\Livewire\TransfersList;
use Liberu\Accounting\TransfersLivewire\TransfersLivewireServiceProvider;

it('renders accounting-transfers-livewire livewire components', function (): void {
    $provider = $this->app->register(TransfersLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TransfersLivewireServiceProvider::class);

    expect(fn () => (new TransfersList())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new TransfersList();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

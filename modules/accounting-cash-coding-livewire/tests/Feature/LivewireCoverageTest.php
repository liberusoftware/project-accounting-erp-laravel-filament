<?php

declare(strict_types=1);

use Liberu\Accounting\CashCodingLivewire\CashCodingLivewireServiceProvider;
use Liberu\Accounting\CashCodingLivewire\Livewire\Batches;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-cash-coding-livewire livewire components', function (): void {
    $provider = $this->app->register(CashCodingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashCodingLivewireServiceProvider::class);

    expect(fn () => (new Batches())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Batches();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

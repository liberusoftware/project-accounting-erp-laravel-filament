<?php

declare(strict_types=1);

use Liberu\Accounting\BankFeedsLivewire\BankFeedsLivewireServiceProvider;
use Liberu\Accounting\BankFeedsLivewire\Livewire\Connections;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-bank-feeds-livewire livewire components', function (): void {
    $provider = $this->app->register(BankFeedsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankFeedsLivewireServiceProvider::class);

    expect(fn () => (new Connections())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Connections();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

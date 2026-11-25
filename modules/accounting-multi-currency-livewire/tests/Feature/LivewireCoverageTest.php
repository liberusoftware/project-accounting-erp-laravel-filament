<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MultiCurrencyLivewire\Livewire\Revaluations;
use Liberu\Accounting\MultiCurrencyLivewire\MultiCurrencyLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-multi-currency-livewire livewire components', function (): void {
    $provider = $this->app->register(MultiCurrencyLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MultiCurrencyLivewireServiceProvider::class);

    expect(fn () => (new Revaluations())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(Revaluations::class)->assertOk();
});

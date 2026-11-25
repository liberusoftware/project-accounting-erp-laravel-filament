<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\OpeningBalancesLivewire\Livewire\Batches;
use Liberu\Accounting\OpeningBalancesLivewire\OpeningBalancesLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-opening-balances-livewire livewire components', function (): void {
    $provider = $this->app->register(OpeningBalancesLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(OpeningBalancesLivewireServiceProvider::class);

    expect(fn () => (new Batches())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(Batches::class)->assertOk();
});

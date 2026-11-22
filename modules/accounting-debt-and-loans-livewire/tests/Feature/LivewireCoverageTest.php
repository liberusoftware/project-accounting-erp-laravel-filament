<?php

declare(strict_types=1);

use Liberu\Accounting\DebtAndLoansLivewire\DebtAndLoansLivewireServiceProvider;
use Liberu\Accounting\DebtAndLoansLivewire\Livewire\DebtPosition;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-debt-and-loans-livewire livewire components', function (): void {
    $provider = $this->app->register(DebtAndLoansLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DebtAndLoansLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new DebtPosition();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

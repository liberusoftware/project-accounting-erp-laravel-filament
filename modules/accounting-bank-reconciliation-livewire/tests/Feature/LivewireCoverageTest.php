<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\BankReconciliationLivewire\BankReconciliationLivewireServiceProvider;
use Liberu\Accounting\BankReconciliationLivewire\Livewire\Sessions;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('renders accounting-bank-reconciliation-livewire livewire components', function (): void {
    $provider = $this->app->register(BankReconciliationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(BankReconciliationLivewireServiceProvider::class);

    expect(fn () => (new Sessions)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Sessions;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});

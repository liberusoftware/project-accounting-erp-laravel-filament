<?php

declare(strict_types=1);

use Liberu\Accounting\AccountReconciliationsLivewire\AccountReconciliationsLivewireServiceProvider;
use Liberu\Accounting\AccountReconciliationsLivewire\Livewire\Reconciliations;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-account-reconciliations-livewire livewire components', function (): void {
    $provider = $this->app->register(AccountReconciliationsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountReconciliationsLivewireServiceProvider::class);

    expect(fn () => (new Reconciliations())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Reconciliations();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

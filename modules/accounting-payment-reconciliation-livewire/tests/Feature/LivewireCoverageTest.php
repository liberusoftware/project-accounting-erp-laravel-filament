<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PaymentReconciliationLivewire\Livewire\SettlementRuns;
use Liberu\Accounting\PaymentReconciliationLivewire\PaymentReconciliationLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-payment-reconciliation-livewire livewire components', function (): void {
    $provider = $this->app->register(PaymentReconciliationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PaymentReconciliationLivewireServiceProvider::class);

    expect(fn () => (new SettlementRuns())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(SettlementRuns::class)->assertOk();
});

<?php

declare(strict_types=1);

use Liberu\Accounting\BankReconciliation\Models\ReconciliationSession;
use Liberu\Accounting\BankReconciliationLivewire\BankReconciliationLivewireServiceProvider;
use Liberu\Accounting\BankReconciliationLivewire\Livewire\Sessions;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-bank-reconciliation-livewire livewire components', function (): void {
    $provider = $this->app->register(BankReconciliationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankReconciliationLivewireServiceProvider::class);

    expect(fn () => (new Sessions())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    ReconciliationSession::query()->create([
        'team_id' => 1,
        'bank_account_id' => 1,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'opening_balance' => 1000.00,
        'statement_balance' => 1000.00,
    ]);

    $component = new Sessions();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});

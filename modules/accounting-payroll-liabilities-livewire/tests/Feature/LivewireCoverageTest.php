<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollLiabilities\Enums\LiabilityStatus;
use Liberu\Accounting\PayrollLiabilities\Models\PayrollLiability;
use Liberu\Accounting\PayrollLiabilitiesLivewire\PayrollLiabilitiesLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-payroll-liabilities-livewire components', function (): void {
    $provider = $this->app->register(PayrollLiabilitiesLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $liability = PayrollLiability::query()->create([
        'team_id' => 1,
        'agency_ref' => 'HMRC',
        'payee_ref' => 'PAYE',
        'liability_ref' => 'LIAB-001',
        'due_on' => '2026-02-19',
        'currency' => 'GBP',
        'amount' => 500.00,
        'paid_amount' => 0.00,
        'status' => LiabilityStatus::Open,
    ]);

    Livewire::test('module-accounting-payroll-liabilities-liabilities')
        ->call('selectLiability', (int) $liability->getKey())
        ->set('amount', '250')
        ->set('allocationRef', 'ALLOC-1')
        ->call('allocate')
        ->assertDispatched('payroll-liability-allocated')
        ->assertOk();
});

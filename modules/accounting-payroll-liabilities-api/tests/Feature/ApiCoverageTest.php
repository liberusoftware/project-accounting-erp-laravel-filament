<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollLiabilitiesApi\PayrollLiabilitiesApiServiceProvider;

it('covers accounting-payroll-liabilities-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PayrollLiabilitiesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PayrollLiabilitiesApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/payroll-liabilities', [
        'agency_ref' => 'HMRC',
        'payee_ref' => 'PAYE-001',
        'liability_ref' => 'LIAB-2026-02',
        'currency' => 'GBP',
        'amount' => 1200.00,
        'paid_amount' => 0.00,
        'due_on' => '2026-03-22',
    ]);
    $storeResponse->assertCreated();
    $liabilityId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/payroll-liabilities')->assertOk();
    $this->getJson('/api/v1/accounting/payroll-liabilities/summary')->assertOk();
    $this->getJson("/api/v1/accounting/payroll-liabilities/{$liabilityId}")->assertOk();
    $this->postJson("/api/v1/accounting/payroll-liabilities/{$liabilityId}/allocate", [
        'amount' => 600.00,
        'allocation_ref' => 'ALLOC-001',
    ])->assertOk();
});

<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollPaymentsApi\PayrollPaymentsApiServiceProvider;

it('covers accounting-payroll-payments-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PayrollPaymentsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PayrollPaymentsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/payroll-payments', [
        'batch_ref' => 'PPB-2026-02',
        'net_pay_ref' => 'NET-001',
        'liability_ref' => 'LIAB-001',
        'currency' => 'GBP',
        'net_pay_amount' => 3500.00,
        'liability_amount' => 1200.00,
        'provider' => 'bacs',
    ]);
    $storeResponse->assertCreated();
    $batchId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/payroll-payments')->assertOk();
    $this->getJson('/api/v1/accounting/payroll-payments/summary')->assertOk();
    $this->getJson("/api/v1/accounting/payroll-payments/{$batchId}")->assertOk();
    $this->postJson("/api/v1/accounting/payroll-payments/{$batchId}/transition", [
        'status' => 'approved',
    ])->assertOk();
});

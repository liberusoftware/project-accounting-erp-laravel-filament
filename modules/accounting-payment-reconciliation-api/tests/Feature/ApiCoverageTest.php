<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PaymentReconciliation\Models\SettlementRun;
use Liberu\Accounting\PaymentReconciliationApi\PaymentReconciliationApiServiceProvider;

it('covers accounting-payment-reconciliation-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PaymentReconciliationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PaymentReconciliationApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/payment-reconciliation', [
        'team_id' => 1,
        'provider' => 'stripe',
        'merchant_ref' => 'acct_123',
        'settlement_ref' => 'SET-001',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'currency' => 'GBP',
        'items' => [
            [
                'external_ref' => 'ch_001',
                'type' => 'charge',
                'currency' => 'GBP',
                'gross_amount' => 100.00,
                'fee_amount' => 2.50,
                'net_amount' => 97.50,
            ],
        ],
    ]);
    $storeResponse->assertCreated();
    $runId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/payment-reconciliation?team_id=1')->assertOk();
    $this->getJson('/api/v1/accounting/payment-reconciliation/summary?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/payment-reconciliation/{$runId}")->assertOk();

    $item = SettlementRun::query()->findOrFail($runId)->items()->firstOrFail();

    $this->postJson("/api/v1/accounting/payment-reconciliation/items/{$item->getKey()}/match", [
        'reference_type' => 'sales_invoice',
        'reference_id' => 'INV-001',
        'amount' => 97.50,
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/payment-reconciliation/{$runId}/missing-items", [
        'items' => [
            ['external_ref' => 'ch_missing', 'expected_amount' => 50.00, 'currency' => 'GBP'],
        ],
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/payment-reconciliation/{$runId}/provider-drift", [
        'field' => 'fee_rate',
        'expected' => '0.025',
        'actual' => '0.029',
        'severity' => 'warning',
    ])->assertCreated();
});

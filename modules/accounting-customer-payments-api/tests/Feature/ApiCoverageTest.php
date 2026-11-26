<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CustomerPaymentsApi\CustomerPaymentsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-customer-payments-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CustomerPaymentsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPaymentsApiServiceProvider::class);

    $paymentStore = $this->postJson('/api/v1/accounting/customer-payments', [
        'customer_id' => 'CUST-001',
        'kind' => 'receipt',
        'reference' => 'PAY-001',
        'currency' => 'USD',
        'amount' => 200.00,
        'gateway_reference' => 'GW-001',
        'metadata' => ['channel' => 'wire'],
    ]);
    $paymentStore->assertStatus(201);
    $paymentId = (string) $paymentStore->json('data.id');

    $this->getJson('/api/v1/accounting/customer-payments')->assertOk();

    $this->postJson("/api/v1/accounting/customer-payments/{$paymentId}/allocations", [
        'document_ref' => 'INV-100',
        'amount' => 80.00,
    ])->assertStatus(201);

    $this->postJson("/api/v1/accounting/customer-payments/{$paymentId}/refund", [
        'amount' => 20.00,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/customer-payments/{$paymentId}/reconcile", [
        'deposit_reference' => 'DEP-001',
    ])->assertOk();
});

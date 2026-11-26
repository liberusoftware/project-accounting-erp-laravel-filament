<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SalesOrdersApi\SalesOrdersApiServiceProvider;

it('covers accounting-sales-orders-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SalesOrdersApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SalesOrdersApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/sales-orders', [
        'customer_id' => 'CUST-001',
        'currency' => 'GBP',
        'order_date' => '2026-02-01',
        'notes' => 'Standard order',
        'items' => [
            ['description' => 'Widget A', 'quantity' => 5, 'unit_price' => 100.00, 'tax_rate' => 20],
        ],
    ]);
    $storeResponse->assertCreated();
    $orderId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/sales-orders')->assertOk();
    $this->getJson("/api/v1/accounting/sales-orders/{$orderId}")->assertOk();

    $this->postJson("/api/v1/accounting/sales-orders/{$orderId}/transition", [
        'status' => 'confirmed',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/sales-orders/{$orderId}/deposits", [
        'reference' => 'DEP-SO-001',
        'amount' => 100.00,
        'currency' => 'GBP',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/sales-orders/{$orderId}/invoice", [
        'amount' => 300.00,
        'invoice_reference' => 'INV-SO-001',
    ])->assertOk();
});

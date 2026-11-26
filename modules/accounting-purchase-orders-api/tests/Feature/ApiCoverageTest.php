<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseOrder;
use Liberu\Accounting\PurchaseOrdersApi\PurchaseOrdersApiServiceProvider;

it('covers accounting-purchase-orders-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PurchaseOrdersApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PurchaseOrdersApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/purchase-orders', [
        'supplier_ref' => 'SUP-001',
        'currency' => 'GBP',
        'order_date' => '2026-02-01',
        'expected_delivery_on' => '2026-02-15',
        'lines' => [
            ['item_ref' => 'ITEM-001', 'description' => 'Server Rack', 'quantity' => 2, 'unit_price' => 500.00],
        ],
    ]);
    $storeResponse->assertCreated();
    $orderId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/purchase-orders')->assertOk();
    $this->getJson("/api/v1/accounting/purchase-orders/{$orderId}")->assertOk();

    // Transition draft -> pending_approval -> approved -> issued
    $this->postJson("/api/v1/accounting/purchase-orders/{$orderId}/transition", ['status' => 'pending_approval'])->assertOk();
    $this->postJson("/api/v1/accounting/purchase-orders/{$orderId}/transition", ['status' => 'approved'])->assertOk();
    $this->postJson("/api/v1/accounting/purchase-orders/{$orderId}/transition", ['status' => 'issued', 'commitment_ref' => 'COM-001'])->assertOk();

    $line = PurchaseOrder::query()->findOrFail($orderId)->lines()->firstOrFail();

    $this->postJson("/api/v1/accounting/purchase-orders/{$orderId}/receipts", [
        'receipt_ref' => 'GRN-001',
        'received_on' => '2026-02-10',
        'lines' => [
            ['line_id' => $line->getKey(), 'quantity' => 2],
        ],
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/purchase-orders/{$orderId}/changes", [
        'changes' => ['notes' => 'Updated delivery window'],
        'reason' => 'Supplier schedule update',
        'actor_ref' => 'USR-1',
    ])->assertCreated();
});

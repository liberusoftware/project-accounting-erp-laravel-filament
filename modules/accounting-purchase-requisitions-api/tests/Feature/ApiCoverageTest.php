<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PurchaseRequisitionsApi\PurchaseRequisitionsApiServiceProvider;

it('covers accounting-purchase-requisitions-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PurchaseRequisitionsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PurchaseRequisitionsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/purchase-requisitions', [
        'requester_ref' => 'EMP-001',
        'title' => 'Office Laptops',
        'currency' => 'GBP',
        'total_amount' => 3000.00,
        'lines' => [
            ['item_ref' => 'LAPTOP-1', 'quantity' => 2, 'unit_price' => 1500.00],
        ],
    ]);
    $storeResponse->assertCreated();
    $reqId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/purchase-requisitions')->assertOk();
    $this->getJson("/api/v1/accounting/purchase-requisitions/{$reqId}")->assertOk();

    $this->postJson("/api/v1/accounting/purchase-requisitions/{$reqId}/transition", [
        'status' => 'submitted',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/purchase-requisitions/{$reqId}/approve", [
        'approver_ref' => 'MGR-001',
        'decision' => 'approved',
        'reason' => 'Within IT budget',
    ])->assertCreated();
});

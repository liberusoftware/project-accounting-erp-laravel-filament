<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SupplierPortalApi\SupplierPortalApiServiceProvider;

it('covers accounting-supplier-portal-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SupplierPortalApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SupplierPortalApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/supplier-portal', [
        'supplier_id' => 'SUP-001',
        'type' => 'invoice',
        'reference' => 'PORTAL-INV-001',
        'currency' => 'GBP',
        'amount' => 500.00,
        'payload' => ['po_number' => 'PO-100'],
    ]);
    $storeResponse->assertCreated();
    $resourceId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/supplier-portal?supplier_id=SUP-001')->assertOk();
    $this->getJson("/api/v1/accounting/supplier-portal/{$resourceId}")->assertOk();
    $this->postJson("/api/v1/accounting/supplier-portal/{$resourceId}/transition", [
        'status' => 'submitted',
    ])->assertOk();
});

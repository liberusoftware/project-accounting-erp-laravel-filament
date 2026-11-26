<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProductAndServiceItemsApi\ProductAndServiceItemsApiServiceProvider;

it('covers accounting-product-and-service-items-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ProductAndServiceItemsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ProductAndServiceItemsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/product-and-service-items', [
        'code' => 'SRV-001',
        'name' => 'Consulting Service',
        'kind' => 'service',
        'sales_price' => 150.00,
        'purchase_price' => 75.00,
        'currency' => 'GBP',
        'status' => 'active',
    ]);
    $storeResponse->assertCreated();
    $itemId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/product-and-service-items?search=Consulting')->assertOk();
    $this->getJson("/api/v1/accounting/product-and-service-items/{$itemId}")->assertOk();
});

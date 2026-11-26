<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CustomerPortalApi\CustomerPortalApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-customer-portal-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CustomerPortalApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPortalApiServiceProvider::class);

    $store = $this->postJson('/api/v1/accounting/customer-portal', [
        'customer_id' => 'CUST-001',
        'type' => 'invoice',
        'reference' => 'PORTAL-INV-001',
        'currency' => 'USD',
        'amount' => 150.00,
        'payload' => ['due_date' => '2026-02-01'],
        'metadata' => ['source' => 'api'],
    ]);
    $store->assertStatus(201);
    $recordId = (string) $store->json('data.id');

    $this->getJson('/api/v1/accounting/customer-portal')->assertOk();

    $this->postJson("/api/v1/accounting/customer-portal/{$recordId}/publish")->assertOk();
});

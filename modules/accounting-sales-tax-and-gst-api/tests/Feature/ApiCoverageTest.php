<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SalesTaxAndGstApi\SalesTaxAndGstApiServiceProvider;

it('covers accounting-sales-tax-and-gst-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SalesTaxAndGstApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SalesTaxAndGstApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/sales-tax-and-gst', [
        'context_id' => 'CTX-001',
        'type' => 'liability',
        'jurisdiction' => 'US-CA',
        'origin' => 'US-CA',
        'destination' => 'US-CA',
        'rate' => 8.25,
        'taxable_base' => 1000.00,
        'liability' => 82.50,
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ]);
    $storeResponse->assertSuccessful();
    $recordId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/sales-tax-and-gst?context_id=CTX-001')->assertOk();
    $this->getJson("/api/v1/accounting/sales-tax-and-gst/{$recordId}")->assertOk();
    $this->postJson("/api/v1/accounting/sales-tax-and-gst/{$recordId}/activate")->assertOk();
    $this->postJson("/api/v1/accounting/sales-tax-and-gst/{$recordId}/close")->assertOk();
});

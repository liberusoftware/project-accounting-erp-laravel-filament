<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\EInvoicingApi\EInvoicingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-e-invoicing-api api provider, controllers, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(EInvoicingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EInvoicingApiServiceProvider::class);

    $store = $this->postJson('/api/v1/accounting/e-invoicing', [
        'legal_entity_id' => 1,
        'document_ref' => 'EINV-2026-001',
        'document_type' => 'invoice',
        'format' => 'ubl',
        'tax_id' => 'GB123456789',
        'counterparty_ref' => 'CUST-001',
        'currency' => 'GBP',
        'payload' => [
            'invoice_number' => 'INV-2026-001',
            'lines' => [
                ['description' => 'Consulting services', 'amount' => 1000.00],
            ],
        ],
        'metadata' => ['channel' => 'api'],
    ]);
    expect($store->getStatusCode())->toBeIn([200, 201]);
    $docId = (string) $store->json('data.id');

    $this->getJson('/api/v1/accounting/e-invoicing')->assertOk();
    $this->getJson('/api/v1/accounting/e-invoicing?status=draft')->assertOk();
    $this->getJson('/api/v1/accounting/e-invoicing?status=invalid_status')->assertStatus(422);
    $this->getJson("/api/v1/accounting/e-invoicing/{$docId}")->assertOk();

    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/validate")->assertOk();

    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/sign", [
        'signature' => 'sig-base64-payload',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/submit", [
        'provider_ref' => 'PEPPOL-GW-001',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/receipt", [
        'accepted' => true,
        'message' => 'Accepted by Peppol access point',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/reconcile")->assertOk();
    $this->postJson("/api/v1/accounting/e-invoicing/{$docId}/archive")->assertOk();
});

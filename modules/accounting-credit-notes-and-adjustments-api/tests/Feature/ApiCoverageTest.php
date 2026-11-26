<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CreditNotesAndAdjustmentsApi\CreditNotesAndAdjustmentsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-credit-notes-and-adjustments-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CreditNotesAndAdjustmentsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CreditNotesAndAdjustmentsApiServiceProvider::class);

    $noteStore = $this->postJson('/api/v1/accounting/credit-notes-and-adjustments', [
        'customer_id' => 'CUST-001',
        'credit_ref' => 'CN-001',
        'reason' => 'Billing adjustment',
        'currency' => 'USD',
        'amount' => 250.00,
        'tax_amount' => 25.00,
        'evidence' => ['ticket' => 'SUP-12'],
        'metadata' => ['source' => 'api'],
    ]);
    $noteStore->assertStatus(201);
    $noteId = (string) $noteStore->json('data.id');

    $this->getJson('/api/v1/accounting/credit-notes-and-adjustments')->assertOk();

    $this->postJson("/api/v1/accounting/credit-notes-and-adjustments/{$noteId}/approve")->assertOk();

    $this->postJson("/api/v1/accounting/credit-notes-and-adjustments/{$noteId}/allocations", [
        'invoice_ref' => 'INV-001',
        'amount' => 100.00,
    ])->assertStatus(201);
});

<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReimbursementsApi\ReimbursementsApiServiceProvider;

it('covers accounting-reimbursements-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ReimbursementsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ReimbursementsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/reimbursements', [
        'payee_ref' => 'EMP-001',
        'source_type' => 'expense_claim',
        'source_id' => 'CLAIM-001',
        'currency' => 'GBP',
        'amount' => 125.00,
    ]);
    $storeResponse->assertCreated();
    $liabilityId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/reimbursements')->assertOk();

    $batchResponse = $this->postJson('/api/v1/accounting/reimbursements/batches', [
        'liability_ids' => [$liabilityId],
    ]);
    $batchResponse->assertSuccessful();
    $batchId = $batchResponse->json('id');

    $this->postJson("/api/v1/accounting/reimbursements/batches/{$batchId}/status", [
        'status' => 'submitted',
        'provider' => 'wise',
        'provider_ref' => 'WISE-001',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/reimbursements/batches/{$batchId}/reconcile", [
        'settled_amount' => 125.00,
        'external_ref' => 'BANK-TX-001',
    ])->assertSuccessful();
});

<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReceiptManagementApi\ReceiptManagementApiServiceProvider;

it('covers accounting-receipt-management-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ReceiptManagementApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ReceiptManagementApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/receipt-management', [
        'file_ref' => 'receipts/2026/02/coffee.pdf',
        'merchant' => 'Coffee Shop',
        'amount' => 12.50,
        'currency' => 'GBP',
        'receipt_date' => '2026-02-10',
    ]);
    $storeResponse->assertCreated();
    $receiptId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/receipt-management')->assertOk();
    $this->getJson("/api/v1/accounting/receipt-management/{$receiptId}")->assertOk();

    $this->postJson("/api/v1/accounting/receipt-management/{$receiptId}/match", [
        'target_type' => 'expense',
        'target_id' => 'EXP-100',
        'matched_amount' => 12.50,
        'confidence' => 0.99,
        'actor_ref' => 'USR-1',
    ])->assertSuccessful();

    $this->postJson('/api/v1/accounting/receipt-management/missing-requests', [
        'receipt_id' => $receiptId,
        'requestee_ref' => 'EMP-001',
        'target_type' => 'expense',
        'target_id' => 'EXP-101',
        'reason' => 'Receipt missing for travel claim',
        'due_on' => '2026-02-28',
    ])->assertSuccessful();
});

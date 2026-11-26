<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TransfersApi\TransfersApiServiceProvider;

it('covers accounting-transfers-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(TransfersApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(TransfersApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/transfers', [
        'source_account_ref' => 'BANK-GBP-01',
        'destination_account_ref' => 'BANK-GBP-02',
        'source_currency' => 'GBP',
        'destination_currency' => 'GBP',
        'source_amount' => 1000.00,
        'destination_amount' => 1000.00,
        'exchange_rate' => 1.0,
        'fee_amount' => 0.00,
        'reference' => 'TRF-001',
    ]);
    $storeResponse->assertCreated();
    $transferId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/transfers')->assertOk();
    $this->postJson("/api/v1/accounting/transfers/{$transferId}/complete")->assertOk();
    $this->postJson("/api/v1/accounting/transfers/{$transferId}/reconcile", [
        'external_ref' => 'STMT-TRF-001',
        'amount' => 1000.00,
        'reconciled_on' => '2026-02-15',
    ])->assertCreated();
});

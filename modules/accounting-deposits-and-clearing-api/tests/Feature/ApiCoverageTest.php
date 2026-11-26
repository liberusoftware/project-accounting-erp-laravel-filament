<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\DepositsAndClearingApi\DepositsAndClearingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-deposits-and-clearing-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DepositsAndClearingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DepositsAndClearingApiServiceProvider::class);

    $fundStore = $this->postJson('/api/v1/accounting/deposits-and-clearing/funds', [
        'source_type' => 'customer_payment',
        'source_id' => 'PAY-100',
        'amount' => 100.00,
        'currency' => 'USD',
        'received_on' => '2026-01-10',
        'metadata' => ['gateway' => 'stripe'],
    ]);
    $fundStore->assertStatus(201);
    $fundId = (int) $fundStore->json('data.id');

    $this->getJson('/api/v1/accounting/deposits-and-clearing/funds')->assertOk();

    $depositStore = $this->postJson('/api/v1/accounting/deposits-and-clearing/deposits', [
        'deposit_ref' => 'DEP-2026-001',
        'provider' => 'stripe',
        'account_ref' => 'BANK-OPERATING',
        'currency' => 'USD',
        'deposit_date' => '2026-01-12',
        'fund_ids' => [$fundId],
    ]);
    $depositStore->assertStatus(201);
    $depositId = (string) $depositStore->json('data.id');

    $this->getJson('/api/v1/accounting/deposits-and-clearing/deposits')->assertOk();

    $this->postJson("/api/v1/accounting/deposits-and-clearing/deposits/{$depositId}/reconcile", [
        'payout_amount' => 97.50,
        'fee_amount' => 2.50,
    ])->assertOk();
});

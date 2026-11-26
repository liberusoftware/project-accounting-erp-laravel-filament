<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CorporateCardsApi\CorporateCardsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-corporate-cards-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CorporateCardsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CorporateCardsApiServiceProvider::class);

    $accountStore = $this->postJson('/api/v1/accounting/corporate-cards', [
        'card_ref' => 'CARD-001',
        'holder_ref' => 'EMP-001',
        'provider_ref' => 'STRIPE-ISSUING',
        'currency' => 'USD',
        'limit_amount' => 5000,
        'controls' => ['daily_limit' => 1000],
    ]);
    $accountStore->assertStatus(201);
    $accountId = (string) $accountStore->json('data.id');

    $this->getJson('/api/v1/accounting/corporate-cards')->assertOk();

    $txnStore = $this->postJson("/api/v1/accounting/corporate-cards/{$accountId}/transactions", [
        'transaction_ref' => 'TXN-001',
        'transaction_date' => '2026-01-15',
        'amount' => 125.50,
        'currency' => 'USD',
        'merchant_ref' => 'CLOUD-HOST',
        'feed_ref' => 'FEED-001',
        'metadata' => ['channel' => 'card'],
    ]);
    $txnStore->assertStatus(201);
    $txnId = (string) $txnStore->json('data.id');

    $this->postJson("/api/v1/accounting/corporate-cards/transactions/{$txnId}/code", [
        'category_ref' => '6100-HOSTING',
        'receipt_ref' => 'RCPT-001',
    ])->assertOk();
});

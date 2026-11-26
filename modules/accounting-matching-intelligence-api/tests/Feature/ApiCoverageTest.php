<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MatchingIntelligenceApi\MatchingIntelligenceApiServiceProvider;

it('covers accounting-matching-intelligence-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(MatchingIntelligenceApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(MatchingIntelligenceApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/matching-intelligence/suggestions', [
        'team_id' => 1,
        'suggestion_ref' => 'SUG-001',
        'source_type' => 'bank_transaction',
        'source_id' => 'TX-100',
        'target_type' => 'sales_invoice',
        'target_id' => 'INV-100',
        'match_type' => 'exact_amount',
        'confidence' => 0.95,
        'explanation' => 'Exact amount and date match',
        'evidence' => [
            ['kind' => 'amount_match'],
        ],
    ]);
    $storeResponse->assertSuccessful();
    $suggestionId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/matching-intelligence/suggestions?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/matching-intelligence/suggestions/{$suggestionId}")->assertOk();

    $this->postJson("/api/v1/accounting/matching-intelligence/suggestions/{$suggestionId}/decide", [
        'accept' => true,
        'automate' => false,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/matching-intelligence/suggestions/{$suggestionId}/feedback", [
        'feedback_type' => 'correct',
        'comment' => 'Verified match',
    ])->assertCreated();

    $this->postJson('/api/v1/accounting/matching-intelligence/thresholds', [
        'team_id' => 1,
        'match_type' => 'exact_amount',
        'minimum_confidence' => 0.90,
        'maximum_amount' => 5000,
        'require_evidence' => true,
    ])->assertCreated();
});

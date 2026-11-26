<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CodingSuggestionsApi\CodingSuggestionsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-coding-suggestions-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CodingSuggestionsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CodingSuggestionsApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/coding-suggestions', [
        'source_ref' => 'TXN-2026-001',
        'target_type' => 'account',
        'target_ref' => '5100',
        'confidence' => 0.92,
        'explanation' => 'Matched historical vendor coding rule.',
    ])->assertCreated();

    $suggestionId = $storeRes->json('data.id');
    expect($suggestionId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/coding-suggestions/{$suggestionId}/feedback", [
        'decision' => 'accepted',
        'note' => 'Correctly categorized.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $this->postJson("/api/v1/accounting/coding-suggestions/{$suggestionId}/review", [
        'reviewer_ref' => 'REV-001',
    ])->assertOk()
        ->assertJsonPath('data.status', 'reviewed');

    $this->getJson('/api/v1/accounting/coding-suggestions')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

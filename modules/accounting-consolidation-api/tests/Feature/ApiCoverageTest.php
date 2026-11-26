<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ConsolidationApi\ConsolidationApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-consolidation-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ConsolidationApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ConsolidationApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/consolidation', [
        'group_ref' => 'GRP-2026-001',
        'name' => 'Acme Holdings Group',
        'reporting_currency' => 'GBP',
    ])->assertCreated();

    $groupId = $storeRes->json('data.id');
    expect($groupId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/consolidation/{$groupId}/entities", [
        'entity_ref' => 'SUB-UK-001',
        'ownership_percent' => 100,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/consolidation/{$groupId}/prepare", [
        'period' => '2026-Q1',
    ])->assertOk()
        ->assertJsonPath('data.status', 'ready');

    $this->postJson("/api/v1/accounting/consolidation/{$groupId}/publish", [
        'translation' => ['USD' => 0.79],
    ])->assertOk()
        ->assertJsonPath('data.status', 'reported');

    $this->getJson('/api/v1/accounting/consolidation')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

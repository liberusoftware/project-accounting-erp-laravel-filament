<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MultiEntityApi\MultiEntityApiServiceProvider;

it('covers accounting-multi-entity-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(MultiEntityApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(MultiEntityApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/multi-entity', [
        'team_id' => 1,
        'entity_ref' => 'ENT-001',
        'code' => 'UK01',
        'name' => 'UK Subsidiary Ltd',
        'base_currency' => 'GBP',
        'timezone' => 'Europe/London',
    ]);
    $storeResponse->assertCreated();
    $entityId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/multi-entity?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/multi-entity/{$entityId}")->assertOk();

    $this->postJson("/api/v1/accounting/multi-entity/{$entityId}/access", [
        'user_ref' => 'USR-1',
        'role' => 'admin',
        'permissions' => ['*'],
        'is_default' => true,
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/multi-entity/{$entityId}/switch", [
        'user_ref' => 'USR-1',
        'session_ref' => 'SES-1',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/multi-entity/{$entityId}/periods", [
        'period_ref' => '2026-Q1',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-03-31',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/multi-entity/{$entityId}/policy", [
        'policy_key' => 'chart_of_accounts',
        'mode' => 'shared',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/multi-entity/{$entityId}/mappings", [
        'mapping_type' => 'account',
        'source_ref' => '1000',
        'target_ref' => '2000',
    ])->assertCreated();

    $this->getJson("/api/v1/accounting/multi-entity/{$entityId}/report")->assertOk();
});

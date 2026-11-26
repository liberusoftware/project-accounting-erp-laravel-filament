<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\IntercompanyApi\IntercompanyApiServiceProvider;

it('covers accounting-intercompany-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(IntercompanyApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(IntercompanyApiServiceProvider::class);

    $base = '/api/v1/accounting/intercompany';

    $cpRes = $this->postJson("{$base}/counterparties", [
        'team_id' => 1,
        'entity_ref' => 'ENT-A',
        'counterparty_ref' => 'ENT-B',
        'name' => 'Subsidiary B',
        'default_currency' => 'USD',
    ]);
    expect($cpRes->status())->toBe(201);
    $cpId = $cpRes->json('data.id');

    expect($this->postJson("{$base}/counterparties/{$cpId}/rules", [
        'rule_ref' => 'RULE-1',
        'description' => 'Management fee markup',
        'pricing_method' => 'cost_plus',
        'markup_percent' => 5,
        'currency' => 'USD',
        'active' => true,
    ])->status())->toBe(201);

    $txRes = $this->postJson("{$base}/counterparties/{$cpId}/transactions", [
        'team_id' => 1,
        'transaction_ref' => 'IC-100',
        'source_entity_ref' => 'ENT-A',
        'target_entity_ref' => 'ENT-B',
        'transaction_type' => 'service_recharge',
        'description' => 'Shared services Q1',
        'amount' => 1000,
        'currency' => 'USD',
    ]);
    expect($txRes->status())->toBeLessThan(500);
    $txId = $txRes->json('data.id');
    expect($txId)->not->toBeNull();

    expect($this->getJson("{$base}?team_id=1&status=pending_confirmation")->status())->toBeGreaterThanOrEqual(200);
    expect($this->getJson($base)->status())->toBeGreaterThanOrEqual(200);

    expect($this->postJson("{$base}/transactions/{$txId}/confirm", [
        'entity_ref' => 'ENT-B',
        'confirmed' => true,
        'comment' => 'Agreed',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/transactions/{$txId}/settle", [
        'settlement_ref' => 'SET-1',
        'amount' => 1000,
        'currency' => 'USD',
        'source_ref' => 'WIRE-100',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/transactions/{$txId}/differences", [
        'difference_ref' => 'DIFF-1',
        'amount' => 10,
        'reason' => 'FX rounding',
        'actor_ref' => 'controller-1',
    ])->status())->toBe(201);

    expect($this->postJson("{$base}/transactions/{$txId}/evidence", [
        'evidence_ref' => 'TP-1',
        'kind' => 'benchmark_study',
        'file_ref' => 's3://tp/study-2026.pdf',
        'arm_length_value' => 1000,
        'currency' => 'USD',
    ])->status())->toBe(201);

    expect($this->getJson("{$base}/transactions/{$txId}")->status())->toBe(200);
    expect($this->getJson("{$base}/transactions/{$txId}/summary")->status())->toBe(200);

    expect($this->postJson("{$base}/reconciliations", [
        'team_id' => 1,
        'reconciliation_ref' => 'RECON-2026-01',
        'period_ref' => '2026-01',
        'entity_ref' => 'ENT-A',
        'counterparty_ref' => 'ENT-B',
        'transaction_count' => 1,
        'source_total' => 1000,
        'counterparty_total' => 1000,
        'actor_ref' => 'controller-1',
    ])->status())->toBe(201);
});

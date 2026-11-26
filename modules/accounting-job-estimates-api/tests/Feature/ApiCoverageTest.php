<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\JobEstimatesApi\JobEstimatesApiServiceProvider;

it('covers accounting-job-estimates-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(JobEstimatesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(JobEstimatesApiServiceProvider::class);

    $base = '/api/v1/accounting/job-estimates';

    $storeRes = $this->postJson($base, [
        'estimate_ref' => 'JE-100',
        'project_ref' => 'PRJ-100',
        'title' => 'Office Renovation Estimate',
        'currency' => 'USD',
    ]);
    expect($storeRes->status())->toBe(201);
    $estimateId = $storeRes->json('data.id');
    expect($estimateId)->not->toBeNull();

    expect($this->getJson($base)->status())->toBe(200);
    expect($this->getJson("{$base}?status=draft")->status())->toBe(200);

    expect($this->postJson("{$base}/{$estimateId}/lines", [
        'line_ref' => 'L-1',
        'line_type' => 'cost',
        'category' => 'labor',
        'description' => 'Site labor',
        'quantity' => 20,
        'rate' => 75,
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$estimateId}/submit")->status())->toBe(200);
    expect($this->postJson("{$base}/{$estimateId}/decide", [
        'actor_ref' => 'PM-1',
        'approved' => true,
        'comment' => 'Approved budget',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$estimateId}/versions", [
        'notes' => 'Baseline revision',
    ])->status())->toBe(201);

    expect($this->postJson("{$base}/{$estimateId}/actuals", [
        'line_ref' => 'L-1',
        'category' => 'labor',
        'amount' => 1200,
        'source_ref' => 'TS-2026-01',
        'occurred_at' => '2026-01-20',
    ])->status())->toBe(200);

    expect($this->getJson("{$base}/{$estimateId}")->status())->toBe(200);
    expect($this->getJson("{$base}/{$estimateId}/comparison")->status())->toBe(200);
    expect($this->getJson("{$base}/{$estimateId}/eac")->status())->toBe(200);

    // Cross-team 404 and no-team 403 guards
    $otherTeamUser = User::factory()->create(['current_team_id' => 2]);
    Sanctum::actingAs($otherTeamUser, ['*']);
    expect($this->getJson("{$base}/{$estimateId}")->status())->toBe(404);

    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    Sanctum::actingAs($noTeamUser, ['*']);
    expect($this->getJson($base)->status())->toBe(403);
});

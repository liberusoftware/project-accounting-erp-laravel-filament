<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\KpiAndGoalsApi\KpiAndGoalsApiServiceProvider;

it('covers accounting-kpi-and-goals-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(KpiAndGoalsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(KpiAndGoalsApiServiceProvider::class);

    $base = '/api/v1/accounting/kpi-and-goals';

    $metricRes = $this->postJson("{$base}/metrics", [
        'team_id' => 1,
        'metric_ref' => 'MET-GM',
        'name' => 'Gross Margin',
        'unit' => 'percent',
        'direction' => 'higher',
        'source_contract' => 'financial-statements',
        'owner_ref' => 'cfo-1',
    ]);
    expect($metricRes->status())->toBe(201);
    $metricId = $metricRes->json('data.id');

    $goalRes = $this->postJson("{$base}/metrics/{$metricId}/goals", [
        'team_id' => 1,
        'goal_ref' => 'GOAL-GM-2026',
        'name' => 'FY2026 Gross Margin Target',
        'owner_ref' => 'cfo-1',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'baseline' => 60,
        'target' => 70,
        'warning_threshold' => 65,
        'critical_threshold' => 60,
    ]);
    expect($goalRes->status())->toBeLessThan(500);
    $goalId = $goalRes->json('data.id');
    expect($goalId)->not->toBeNull();

    expect($this->getJson("{$base}/goals?team_id=1")->status())->toBe(200);

    expect($this->postJson("{$base}/goals/{$goalId}/measurements", [
        'period_ref' => '2026-Q1',
        'measured_on' => '2026-03-31',
        'value' => 68.5,
        'source_ref' => 'GL-2026-Q1',
    ])->status())->toBe(201);

    expect($this->postJson("{$base}/goals/{$goalId}/commentary", [
        'body' => 'On track for annual margin goal.',
        'period_ref' => '2026-Q1',
    ])->status())->toBe(201);

    expect($this->getJson("{$base}/goals/{$goalId}")->status())->toBe(200);
});

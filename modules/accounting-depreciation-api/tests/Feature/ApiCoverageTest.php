<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\DepreciationApi\DepreciationApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-depreciation-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DepreciationApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DepreciationApiServiceProvider::class);

    $scheduleStore = $this->postJson('/api/v1/accounting/depreciation/schedules', [
        'asset_ref' => 'AST-001',
        'book_ref' => 'MAIN',
        'method' => 'straight_line',
        'convention' => 'full_month',
        'useful_life_months' => 12,
        'cost' => 1200.00,
        'residual_value' => 0.00,
        'start_date' => '2026-01-01',
        'currency' => 'USD',
        'metadata' => ['category' => 'equipment'],
    ]);
    $scheduleStore->assertStatus(201);
    $scheduleId = (string) $scheduleStore->json('data.id');

    $this->getJson('/api/v1/accounting/depreciation/schedules')->assertOk();
    $this->getJson('/api/v1/accounting/depreciation/forecast')->assertOk();

    $runStore = $this->postJson("/api/v1/accounting/depreciation/schedules/{$scheduleId}/runs", [
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]);
    $runStore->assertStatus(201);
    $runId = (string) $runStore->json('data.id');

    $this->postJson("/api/v1/accounting/depreciation/runs/{$runId}/post", [
        'journal_ref' => 'JRN-DEP-001',
    ])->assertOk();
});

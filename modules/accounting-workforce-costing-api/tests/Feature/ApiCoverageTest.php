<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WorkforceCostingApi\WorkforceCostingApiServiceProvider;

it('covers accounting-workforce-costing-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(WorkforceCostingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(WorkforceCostingApiServiceProvider::class);

    $this->postJson('/api/v1/accounting/workforce-costing/rules', [
        'name' => 'Engineering Rate',
        'allocation_type' => 'project',
        'allocation_ref' => 'PRJ-001',
        'hourly_rate' => 85.00,
        'capitalize' => true,
        'active' => true,
    ])->assertCreated();

    $this->getJson('/api/v1/accounting/workforce-costing/rules')->assertOk();

    $costResponse = $this->postJson('/api/v1/accounting/workforce-costing/costs', [
        'worker_ref' => 'EMP-001',
        'cost_date' => '2026-02-10',
        'hours' => 8,
        'hourly_rate' => 85.00,
        'amount' => 680.00,
    ]);
    $costResponse->assertCreated();
    $costId = $costResponse->json('id');

    $this->getJson('/api/v1/accounting/workforce-costing/costs')->assertOk();
    $this->postJson("/api/v1/accounting/workforce-costing/costs/{$costId}/allocate", [
        'allocation_type' => 'project',
        'allocation_ref' => 'PRJ-001',
    ])->assertOk();
    $this->postJson("/api/v1/accounting/workforce-costing/costs/{$costId}/capitalize")->assertOk();
    $this->getJson('/api/v1/accounting/workforce-costing/profitability?from=2026-01-01&to=2026-12-31')->assertOk();
});

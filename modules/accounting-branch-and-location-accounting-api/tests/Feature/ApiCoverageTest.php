<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BranchLocationAccountingApi\BranchLocationAccountingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-branch-and-location-accounting-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BranchLocationAccountingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BranchLocationAccountingApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/branches', [
        'code' => 'LON-01',
        'name' => 'London Branch',
        'location' => 'London, UK',
        'local_tax_code' => 'UK-VAT',
        'sequence_prefix' => 'LON',
        'allocation_rule' => 'headcount',
        'performance_target' => 250000,
        'statutory_reference' => 'STAT-LON-1',
    ]);
    expect($storeRes->status())->toBe(201);
    $branchId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/branches')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/branches?status=active')->status())->toBe(200);

    $allocateRes = $this->postJson("/api/v1/accounting/branches/{$branchId}/allocate", [
        'amount' => 1500.50,
    ]);
    expect($allocateRes->status())->toBe(200);
});

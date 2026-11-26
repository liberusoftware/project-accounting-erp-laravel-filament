<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\LeasesApi\LeasesApiServiceProvider;

it('covers accounting-leases-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(LeasesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(LeasesApiServiceProvider::class);

    $base = '/api/v1/accounting/leases';

    $storeRes = $this->postJson($base, [
        'team_id' => 1,
        'lease_ref' => 'LS-100',
        'name' => 'HQ Office Lease',
        'lessor_ref' => 'LANDLORD-1',
        'asset_ref' => 'ROU-100',
        'commencement_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'currency' => 'USD',
        'payment_amount' => 5000,
        'payment_frequency' => 'monthly',
        'discount_rate' => 5.0,
        'useful_life_months' => 12,
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $leaseId = $storeRes->json('data.id');
    expect($leaseId)->not->toBeNull();

    expect($this->getJson("{$base}?team_id=1")->status())->toBe(200);

    expect($this->postJson("{$base}/{$leaseId}/schedule")->status())->toBe(200);

    expect($this->postJson("{$base}/{$leaseId}/modify", [
        'modification_ref' => 'MOD-1',
        'effective_date' => '2026-06-01',
        'kind' => 'payment_change',
        'new_payment_amount' => 5500,
        'reason' => 'Annual CPI adjustment',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$leaseId}/disclosure", [
        'as_of_date' => '2026-12-31',
    ])->status())->toBe(201);

    expect($this->getJson("{$base}/{$leaseId}")->status())->toBe(200);
});

<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Dimensions\Models\Dimension;
use Liberu\Accounting\DimensionsApi\DimensionsApiServiceProvider;
use Liberu\Accounting\DimensionsApi\Http\Resources\DimensionResource;
use Liberu\Accounting\DimensionsApi\Policies\DimensionsPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-dimensions-and-tracking-api api provider, policies, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DimensionsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DimensionsApiServiceProvider::class);

    $policy = new DimensionsPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $record = (new Dimension())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();
    expect($policy->delete($allowUser, $record))->toBeTrue();
    expect($policy->delete($denyUser, $record))->toBeFalse();
    expect($policy->delete(null, $record))->toBeFalse();

    // Non-Dimension resource branch
    expect((new DimensionResource(null))->toArray(Request::create('/')))->toBe([]);

    $dimStore = $this->postJson('/api/v1/accounting/dimensions-and-tracking', [
        'code' => 'DEPT',
        'name' => 'Department',
        'kind' => 'department',
        'description' => 'Cost department',
        'is_required' => false,
        'is_active' => true,
        'metadata' => ['owner' => 'finance'],
    ]);
    $dimStore->assertStatus(201);
    $dimId = (string) $dimStore->json('data.id');

    // Duplicate triggers InvalidDimension catch branch
    $this->postJson('/api/v1/accounting/dimensions-and-tracking', [
        'code' => 'DEPT',
        'name' => 'Department Duplicate',
        'kind' => 'department',
    ])->assertStatus(422);

    $this->getJson('/api/v1/accounting/dimensions-and-tracking')->assertOk();
    $this->getJson('/api/v1/accounting/dimensions-and-tracking?kind=department')->assertOk();
    $this->getJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}")->assertOk();

    $this->patchJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}", [
        'name' => 'Department Updated',
        'is_active' => true,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}/values", [
        'code' => 'FIN',
        'name' => 'Finance',
        'is_active' => true,
    ])->assertStatus(201);

    // Duplicate value code triggers InvalidDimension catch branch
    $this->postJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}/values", [
        'code' => 'FIN',
        'name' => 'Finance Duplicate',
        'is_active' => true,
    ])->assertStatus(422);

    // Show with populated values executes DimensionResource values mapping closure
    $this->getJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}")->assertOk();

    $this->postJson('/api/v1/accounting/dimensions-and-tracking/validate', [
        'dimensions' => ['department' => 'FIN'],
    ])->assertOk();

    // Unknown dimension value triggers InvalidDimension catch branch
    $this->postJson('/api/v1/accounting/dimensions-and-tracking/validate', [
        'dimensions' => ['department' => 'UNKNOWN'],
    ])->assertStatus(422);

    $this->postJson('/api/v1/accounting/dimensions-and-tracking/allocate', [
        'allocation_key' => 'ALLOC-001',
        'amount' => 1000,
        'currency' => 'USD',
        'allocations' => [
            ['percentage' => 100, 'dimensions' => ['department' => 'FIN']],
        ],
    ])->assertStatus(201);

    // Invalid total percentage triggers InvalidDimension catch branch
    $this->postJson('/api/v1/accounting/dimensions-and-tracking/allocate', [
        'allocation_key' => 'ALLOC-BAD',
        'amount' => 1000,
        'currency' => 'USD',
        'allocations' => [
            ['percentage' => 50, 'dimensions' => ['department' => 'FIN']],
        ],
    ])->assertStatus(422);

    $this->getJson('/api/v1/accounting/dimensions-and-tracking/balances')->assertOk();
    $this->getJson('/api/v1/accounting/dimensions-and-tracking/balances?allocation_key=ALLOC-001')->assertOk();

    // Deleting a dimension with values returns 409
    $this->deleteJson("/api/v1/accounting/dimensions-and-tracking/{$dimId}")->assertStatus(409);

    // Deleting an empty dimension returns 204
    $emptyDim = $this->postJson('/api/v1/accounting/dimensions-and-tracking', [
        'code' => 'LOC',
        'name' => 'Location',
        'kind' => 'location',
        'is_active' => true,
    ]);
    $emptyDim->assertStatus(201);
    $emptyDimId = (string) $emptyDim->json('data.id');
    $this->deleteJson("/api/v1/accounting/dimensions-and-tracking/{$emptyDimId}")->assertNoContent();
});

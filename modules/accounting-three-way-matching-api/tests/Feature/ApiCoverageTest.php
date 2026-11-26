<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ThreeWayMatching\Models\MatchRecord;
use Liberu\Accounting\ThreeWayMatchingApi\Policies\ThreeWayMatchingPolicy;
use Liberu\Accounting\ThreeWayMatchingApi\ThreeWayMatchingApiServiceProvider;

it('covers accounting-three-way-matching-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ThreeWayMatchingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ThreeWayMatchingApiServiceProvider::class);

    foreach (app('router')->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/accounting/three-way-matching')) {
            $route->middleware(SubstituteBindings::class);
        }
    }

    $policy = new ThreeWayMatchingPolicy();
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
    $stub = (object) ['id' => 1];
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $stub))->toBeTrue();
    expect($policy->view($denyUser, $stub))->toBeFalse();
    expect($policy->view(null, $stub))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $stub))->toBeTrue();
    expect($policy->update($denyUser, $stub))->toBeFalse();
    expect($policy->update(null, $stub))->toBeFalse();

    // Evaluate with quantity mismatch to generate a MatchException
    $eval1 = $this->postJson('/api/v1/accounting/three-way-matching', [
        'purchase_order_type' => 'purchase_order',
        'purchase_order_id' => 'PO-001',
        'receipt_type' => 'receipt',
        'receipt_id' => 'GRN-001',
        'bill_type' => 'supplier_bill',
        'bill_id' => 'SB-001',
        'currency' => 'GBP',
        'ordered_quantity' => 10,
        'received_quantity' => 10,
        'billed_quantity' => 12,
        'ordered_unit_price' => 50.00,
        'billed_unit_price' => 50.00,
        'expected_tax' => 100.00,
        'billed_tax' => 100.00,
        'quantity_tolerance' => 0,
        'price_tolerance' => 0,
        'tax_tolerance' => 0,
    ]);
    $eval1->assertSuccessful();
    $match1Id = $eval1->json('data.id');

    $this->getJson('/api/v1/accounting/three-way-matching')->assertOk();
    $this->getJson("/api/v1/accounting/three-way-matching/exceptions?match_id={$match1Id}")->assertOk();

    $this->postJson("/api/v1/accounting/three-way-matching/{$match1Id}/evidence", [
        'source_type' => 'grn_pdf',
        'source_id' => 'DOC-001',
        'snapshot' => ['signed_by' => 'Warehouse'],
    ])->assertOk();

    $this->getJson("/api/v1/accounting/three-way-matching/{$match1Id}")->assertOk();

    $exception = MatchRecord::query()->findOrFail($match1Id)->exceptions()->firstOrFail();
    $this->postJson("/api/v1/accounting/three-way-matching/exceptions/{$exception->getKey()}/resolve", [
        'resolution' => 'Supplier confirmed extra delivery',
        'waive' => true,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/three-way-matching/{$match1Id}/approve", [
        'override_reason' => 'Approved by finance lead',
    ])->assertOk();

    // Evaluate second match and reject it
    $eval2 = $this->postJson('/api/v1/accounting/three-way-matching', [
        'purchase_order_type' => 'purchase_order',
        'purchase_order_id' => 'PO-002',
        'receipt_type' => 'receipt',
        'receipt_id' => 'GRN-002',
        'bill_type' => 'supplier_bill',
        'bill_id' => 'SB-002',
        'currency' => 'GBP',
        'ordered_quantity' => 5,
        'received_quantity' => 5,
        'billed_quantity' => 5,
        'ordered_unit_price' => 20.00,
        'billed_unit_price' => 25.00,
        'expected_tax' => 20.00,
        'billed_tax' => 25.00,
    ]);
    $eval2->assertSuccessful();
    $match2Id = $eval2->json('data.id');
    $this->postJson("/api/v1/accounting/three-way-matching/{$match2Id}/reject", [
        'reason' => 'Price exceeds PO',
    ])->assertOk();
});

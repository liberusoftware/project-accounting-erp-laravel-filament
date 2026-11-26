<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\DebtAndLoansApi\DebtAndLoansApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-debt-and-loans-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DebtAndLoansApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DebtAndLoansApiServiceProvider::class);

    $facilityStore = $this->postJson('/api/v1/accounting/debt-and-loans/facilities', [
        'facility_ref' => 'FAC-001',
        'lender_ref' => 'BANK-001',
        'currency' => 'USD',
        'limit_amount' => 500000.00,
        'interest_rate' => 5.25,
        'start_date' => '2026-01-01',
        'maturity_date' => '2029-01-01',
        'metadata' => ['type' => 'revolving'],
    ]);
    $facilityStore->assertStatus(201);
    $facilityId = (string) $facilityStore->json('data.id');

    $this->getJson('/api/v1/accounting/debt-and-loans/facilities')->assertOk();
    $this->getJson('/api/v1/accounting/debt-and-loans/position')->assertOk();

    $movementStore = $this->postJson("/api/v1/accounting/debt-and-loans/facilities/{$facilityId}/movements", [
        'kind' => 'drawdown',
        'principal_amount' => 100000.00,
        'interest_amount' => 0,
        'fee_amount' => 500.00,
        'movement_date' => '2026-01-15',
        'due_date' => '2026-06-15',
        'metadata' => ['tranche' => 'A'],
    ]);
    $movementStore->assertStatus(201);
    $movementId = (string) $movementStore->json('data.id');

    $this->postJson("/api/v1/accounting/debt-and-loans/movements/{$movementId}/reconcile", [
        'journal_ref' => 'JRN-DEBT-001',
    ])->assertOk();
});

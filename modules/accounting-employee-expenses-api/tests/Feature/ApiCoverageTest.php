<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\EmployeeExpensesApi\EmployeeExpensesApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-employee-expenses-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(EmployeeExpensesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(EmployeeExpensesApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/employee-expenses', [
        'team_id' => 1,
        'employee_ref' => 'EMP-100',
        'claim_ref' => 'CLM-100',
        'currency' => 'USD',
        'project_ref' => 'PRJ-1',
    ]);
    expect($storeResponse->status())->toBeLessThan(500);
    $claimId = $storeResponse->json('data.id');
    expect($claimId)->not->toBeNull();

    $indexResponse = $this->getJson('/api/v1/accounting/employee-expenses?team_id=1&status=draft');
    expect($indexResponse->status())->toBe(200);

    $indexAllResponse = $this->getJson('/api/v1/accounting/employee-expenses');
    expect($indexAllResponse->status())->toBe(200);

    $invalidStatusResponse = $this->getJson('/api/v1/accounting/employee-expenses?status=not-a-status');
    expect($invalidStatusResponse->status())->toBe(422);

    $showResponse = $this->getJson("/api/v1/accounting/employee-expenses/{$claimId}");
    expect($showResponse->status())->toBe(200);

    $itemResponse = $this->postJson("/api/v1/accounting/employee-expenses/{$claimId}/items", [
        'category_ref' => 'TRAVEL',
        'spent_on' => '2026-01-15',
        'description' => 'Airfare to client site',
        'amount' => 350.00,
        'tax_amount' => 35.00,
        'merchant' => 'Airline Co',
    ]);
    expect($itemResponse->status())->toBeLessThan(500);

    $submitResponse = $this->postJson("/api/v1/accounting/employee-expenses/{$claimId}/submit");
    expect($submitResponse->status())->toBe(200);

    $decideResponse = $this->postJson("/api/v1/accounting/employee-expenses/{$claimId}/decide", [
        'approved' => true,
        'reason' => 'Approved for reimbursement',
    ]);
    expect($decideResponse->status())->toBe(200);

    $reimburseResponse = $this->postJson("/api/v1/accounting/employee-expenses/{$claimId}/reimburse");
    expect($reimburseResponse->status())->toBe(200);

    $postResponse = $this->postJson("/api/v1/accounting/employee-expenses/{$claimId}/post");
    expect($postResponse->status())->toBe(200);
});

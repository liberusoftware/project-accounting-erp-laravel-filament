<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CloseManagementApi\CloseManagementApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-close-management-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CloseManagementApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CloseManagementApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/close-management', [
        'cycle_ref' => 'CLOSE-2026-01',
        'period' => '2026-01',
        'due_date' => '2026-02-05',
    ])->assertCreated();

    $cycleId = $storeRes->json('data.id');
    expect($cycleId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/close-management/{$cycleId}/checklist", [
        'checklist' => [
            ['task' => 'Reconcile bank accounts', 'completed' => true],
        ],
    ])->assertOk();

    $this->postJson("/api/v1/accounting/close-management/{$cycleId}/evidence", [
        'reference' => 'REC-2026-01',
        'type' => 'reconciliation',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/close-management/{$cycleId}/certify", [
        'certifier_ref' => 'CFO-001',
    ])->assertOk()
        ->assertJsonPath('data.status', 'certified');

    $this->postJson("/api/v1/accounting/close-management/{$cycleId}/lock")
        ->assertOk()
        ->assertJsonPath('data.status', 'locked');

    $this->postJson("/api/v1/accounting/close-management/{$cycleId}/reopen", [
        'reason' => 'Late audit adjustment required',
    ])->assertOk()
        ->assertJsonPath('data.status', 'reopened');

    $this->getJson('/api/v1/accounting/close-management')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CollectionsApi\CollectionsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-collections-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CollectionsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CollectionsApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/collections', [
        'case_ref' => 'COL-2026-001',
        'customer_ref' => 'CUST-009',
        'balance' => 1200,
        'interest_rate' => 5.5,
    ])->assertCreated();

    $caseId = $storeRes->json('data.id');
    expect($caseId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/collections/{$caseId}/reminders", [
        'scheduled_for' => '2026-06-01',
        'channel' => 'email',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/collections/{$caseId}/promises", [
        'due_on' => '2026-06-10',
        'amount' => 600,
    ])->assertOk()
        ->assertJsonPath('data.stage', 'promise-to-pay');

    $this->postJson("/api/v1/accounting/collections/{$caseId}/disputes", [
        'reason' => 'Quantity mismatch on line 2',
    ])->assertOk()
        ->assertJsonPath('data.stage', 'dispute');

    $this->postJson("/api/v1/accounting/collections/{$caseId}/write-off", [
        'reason' => 'Debtor insolvency confirmed',
        'amount' => 1200,
    ])->assertOk()
        ->assertJsonPath('data.stage', 'written-off');

    $this->getJson('/api/v1/accounting/collections')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

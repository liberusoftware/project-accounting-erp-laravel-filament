<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CashPositionApi\CashPositionApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-cash-position-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CashPositionApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashPositionApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/cash-position', [
        'view_ref' => 'MAIN-GBP',
        'entity_ref' => 'ENT-001',
        'currency' => 'GBP',
        'ledger_balance' => 5000,
        'available_balance' => 4500,
    ])->assertCreated();

    $positionId = $storeRes->json('data.id');
    expect($positionId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/cash-position/{$positionId}/refresh", [
        'ledger_balance' => 5200,
        'available_balance' => 4800,
        'outstanding_receipts' => 300,
        'outstanding_payments' => 100,
        'committed_cash' => 200,
    ])->assertOk();

    $this->getJson('/api/v1/accounting/cash-position')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

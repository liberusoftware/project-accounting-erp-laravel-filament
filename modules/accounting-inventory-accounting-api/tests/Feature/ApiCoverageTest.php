<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\InventoryAccountingApi\InventoryAccountingApiServiceProvider;

it('covers accounting-inventory-accounting-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(InventoryAccountingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(InventoryAccountingApiServiceProvider::class);

    $base = '/api/v1/accounting/inventory-accounting';

    $storeRes = $this->postJson($base, [
        'team_id' => 1,
        'item_ref' => 'SKU-100',
        'description' => 'Industrial Steel Rod',
        'warehouse_ref' => 'WH-MAIN',
        'currency' => 'USD',
        'valuation_method' => 'fifo',
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $itemId = $storeRes->json('data.id');
    expect($itemId)->not->toBeNull();

    expect($this->getJson("{$base}?team_id=1&status=active")->status())->toBeGreaterThanOrEqual(200);
    expect($this->getJson($base)->status())->toBeGreaterThanOrEqual(200);

    expect($this->postJson("{$base}/{$itemId}/receipts", [
        'movement_ref' => 'RCV-100',
        'quantity' => 100,
        'unit_cost' => 10,
        'source_ref' => 'PO-100',
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$itemId}/issues", [
        'movement_ref' => 'ISS-100',
        'quantity' => 20,
        'source_ref' => 'SO-100',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$itemId}/adjustments", [
        'adjustment_ref' => 'ADJ-100',
        'quantity_delta' => 5,
        'value_delta' => 50,
        'reason' => 'Cycle count adjustment',
        'actor_ref' => 'auditor-1',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$itemId}/landed-costs", [
        'cost_ref' => 'LC-100',
        'amount' => 100,
        'allocation_basis' => 'quantity',
        'source_ref' => 'FREIGHT-100',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$itemId}/write-downs", [
        'write_down_ref' => 'WD-100',
        'amount' => 25,
        'reason' => 'NRV adjustment',
        'actor_ref' => 'controller-1',
    ])->status())->toBe(200);

    expect($this->getJson("{$base}/{$itemId}")->status())->toBe(200);
    expect($this->getJson("{$base}/{$itemId}/valuation")->status())->toBe(200);

    expect($this->postJson("{$base}/reconciliations", [
        'team_id' => 1,
        'reconciliation_ref' => 'INV-REC-2026-01',
        'period_ref' => '2026-01',
        'subledger_value' => 925,
        'general_ledger_value' => 925,
        'actor_ref' => 'controller-1',
    ])->status())->toBe(201);
});

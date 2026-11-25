<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\InventoryAccounting\Actions\AdjustInventory;
use Liberu\Accounting\InventoryAccounting\Actions\ApplyLandedCost;
use Liberu\Accounting\InventoryAccounting\Actions\CreateInventoryItem;
use Liberu\Accounting\InventoryAccounting\Actions\IssueInventory;
use Liberu\Accounting\InventoryAccounting\Actions\ReceiveInventory;
use Liberu\Accounting\InventoryAccounting\Actions\ReconcileInventory;
use Liberu\Accounting\InventoryAccounting\Actions\WriteDownInventory;
use Liberu\Accounting\InventoryAccounting\Enums\InventoryStatus;
use Liberu\Accounting\InventoryAccounting\Enums\MovementType;
use Liberu\Accounting\InventoryAccounting\Enums\ValuationMethod;
use Liberu\Accounting\InventoryAccounting\Events\InventoryReconciled;
use Liberu\Accounting\InventoryAccounting\Exceptions\InvalidInventory;
use Liberu\Accounting\InventoryAccounting\InventoryAccountingServiceProvider;
use Liberu\Accounting\InventoryAccounting\Models\CostLayer;
use Liberu\Accounting\InventoryAccounting\Models\InventoryAdjustment;
use Liberu\Accounting\InventoryAccounting\Models\InventoryItem;
use Liberu\Accounting\InventoryAccounting\Models\InventoryMovement;
use Liberu\Accounting\InventoryAccounting\Models\InventoryReconciliation;
use Liberu\Accounting\InventoryAccounting\Models\InventoryWriteDown;
use Liberu\Accounting\InventoryAccounting\Models\LandedCost;
use Liberu\Accounting\InventoryAccounting\Queries\InventoryQuery;

it('boots inventory accounting service provider and covers enums, exception, and event', function (): void {
    $provider = $this->app->register(InventoryAccountingServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(InventoryAccountingServiceProvider::class)
        ->and(app(InventoryQuery::class))->toBe(app(InventoryQuery::class))
        ->and(array_column(InventoryStatus::cases(), 'value'))->toBe(['active', 'closed'])
        ->and(array_column(MovementType::cases(), 'value'))->toBe(['receipt', 'issue', 'adjustment', 'landed_cost', 'write_down'])
        ->and(array_column(ValuationMethod::cases(), 'value'))->toBe(['perpetual', 'periodic', 'fifo', 'weighted_average'])
        ->and((new InvalidInventory('Inventory error'))->getMessage())->toBe('Inventory error');

    Event::fake([InventoryReconciled::class]);
    $reconciliation = app(ReconcileInventory::class)->handle([
        'team_id' => 1,
        'reconciliation_ref' => 'REC-EVT-001',
        'period_ref' => '2026-01',
        'subledger_value' => 100.00,
        'general_ledger_value' => 100.00,
    ]);
    $event = new InventoryReconciled($reconciliation);
    event($event);

    expect($event->reconciliation->is($reconciliation))->toBeTrue();
    Event::assertDispatched(InventoryReconciled::class);
});

it('executes full inventory lifecycle, relations, reconciliation, and queries', function (): void {
    $query = app(InventoryQuery::class);
    expect($query->paginate(5)->total())->toBe(0);

    $item = app(CreateInventoryItem::class)->handle([
        'team_id' => 5,
        'item_ref' => 'SKU-100',
        'description' => 'Industrial Valve',
        'warehouse_ref' => 'WH-MAIN',
        'currency' => 'usd',
        'valuation_method' => ValuationMethod::FIFO->value,
        'metadata' => ['category' => 'hardware'],
    ]);

    expect($item)->toBeInstanceOf(InventoryItem::class)
        ->and($item->currency)->toBe('USD')
        ->and($item->valuation_method)->toBe(ValuationMethod::FIFO)
        ->and($item->status)->toBe(InventoryStatus::Active)
        ->and($item->metadata)->toBe(['category' => 'hardware']);

    // Valuation with zero quantity on hand
    $zeroValuation = $query->valuation($item);
    expect($zeroValuation)->toBe([
        'item_ref' => 'SKU-100',
        'quantity_on_hand' => 0.0,
        'inventory_value' => 0.0,
        'average_unit_cost' => 0.0,
        'valuation_method' => 'fifo',
    ]);

    // Receive two FIFO layers
    $receive = app(ReceiveInventory::class);
    $layer1 = $receive->handle($item, [
        'quantity' => 10,
        'unit_cost' => 20.00,
        'movement_ref' => 'RCP-001',
        'source_ref' => 'PO-001',
        'occurred_at' => now()->subDay(),
    ]);
    $layer2 = $receive->handle($item, [
        'quantity' => 5,
        'unit_cost' => 30.00,
        'movement_ref' => 'RCP-002',
        'source_ref' => 'PO-002',
        'occurred_at' => now(),
    ]);

    $item->refresh();
    expect($layer1)->toBeInstanceOf(CostLayer::class)
        ->and($layer1->item->is($item))->toBeTrue()
        ->and((float) $item->quantity_on_hand)->toBe(15.0)
        ->and((float) $item->inventory_value)->toBe(350.0);

    // Issue across both FIFO layers (10 @ 20 + 2 @ 30 = 260)
    $issuedCost = app(IssueInventory::class)->handle($item, [
        'quantity' => 12,
        'movement_ref' => 'ISS-001',
        'source_ref' => 'SO-001',
        'occurred_at' => now(),
    ]);

    $item->refresh();
    $layer1->refresh();
    $layer2->refresh();
    expect($issuedCost)->toBe(260.0)
        ->and((float) $layer1->quantity_remaining)->toBe(0.0)
        ->and((float) $layer2->quantity_remaining)->toBe(3.0)
        ->and((float) $item->quantity_on_hand)->toBe(3.0)
        ->and((float) $item->inventory_value)->toBe(90.0);

    // Apply landed cost
    $landedCost = app(ApplyLandedCost::class)->handle($item, [
        'cost_ref' => 'LC-001',
        'amount' => 30.00,
        'allocation_basis' => 'value',
        'source_ref' => 'FRT-001',
    ]);

    $item->refresh();
    expect($landedCost)->toBeInstanceOf(LandedCost::class)
        ->and($landedCost->item->is($item))->toBeTrue()
        ->and((float) $item->inventory_value)->toBe(120.0);

    // Positive adjustment
    $adjustment = app(AdjustInventory::class)->handle($item, [
        'adjustment_ref' => 'ADJ-001',
        'quantity_delta' => 1,
        'value_delta' => 40.00,
        'reason' => 'Cycle count surplus',
        'actor_ref' => 'user-7',
    ]);

    $item->refresh();
    expect($adjustment)->toBeInstanceOf(InventoryAdjustment::class)
        ->and($adjustment->item->is($item))->toBeTrue()
        ->and((float) $item->quantity_on_hand)->toBe(4.0)
        ->and((float) $item->inventory_value)->toBe(160.0);

    // Write-down inventory
    $writeDown = app(WriteDownInventory::class)->handle($item, [
        'write_down_ref' => 'WD-001',
        'amount' => 20.00,
        'reason' => 'NRV adjustment',
        'actor_ref' => 'user-8',
    ]);

    $item->refresh();
    expect($writeDown)->toBeInstanceOf(InventoryWriteDown::class)
        ->and($writeDown->item->is($item))->toBeTrue()
        ->and((float) $item->inventory_value)->toBe(140.0);

    // Check all item relations and movement relation
    $movement = $item->movements()->first();
    expect($movement)->toBeInstanceOf(InventoryMovement::class)
        ->and($movement->item->is($item))->toBeTrue()
        ->and($item->layers()->count())->toBe(2)
        ->and($item->movements()->count())->toBe(5)
        ->and($item->adjustments()->count())->toBe(1)
        ->and($item->landedCosts()->count())->toBe(1)
        ->and($item->writeDowns()->count())->toBe(1);

    // Non-zero valuation
    $valuation = $query->valuation($item);
    expect($valuation)->toBe([
        'item_ref' => 'SKU-100',
        'quantity_on_hand' => 4.0,
        'inventory_value' => 140.0,
        'average_unit_cost' => 35.0,
        'valuation_method' => 'fifo',
    ]);

    // Query filtering and pagination bounds
    $page = $query->paginate(5, InventoryStatus::Active, 200);
    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100);

    $closedPage = $query->paginate(5, InventoryStatus::Closed, 0);
    expect($closedPage->total())->toBe(0)
        ->and($closedPage->perPage())->toBe(1);

    // Reconcile inventory (reconciled and variance branches)
    $reconcile = app(ReconcileInventory::class);
    $matched = $reconcile->handle([
        'team_id' => 5,
        'reconciliation_ref' => 'REC-001',
        'period_ref' => '2026-03',
        'subledger_value' => 140.00,
        'general_ledger_value' => 140.00,
        'actor_ref' => 'controller-1',
        'metadata' => ['reviewed' => true],
    ], $item);

    expect($matched)->toBeInstanceOf(InventoryReconciliation::class)
        ->and((float) $matched->variance)->toBe(0.0)
        ->and($matched->status)->toBe('reconciled')
        ->and($matched->metadata)->toBe(['reviewed' => true]);

    $withVariance = $reconcile->handle([
        'team_id' => 5,
        'reconciliation_ref' => 'REC-002',
        'period_ref' => '2026-04',
        'subledger_value' => 140.00,
        'general_ledger_value' => 135.50,
    ]);

    expect((float) $withVariance->variance)->toBe(4.5)
        ->and($withVariance->status)->toBe('variance')
        ->and($withVariance->actor_ref)->toBe('system');
});

it('enforces validation and error branches across inventory actions', function (): void {
    $create = app(CreateInventoryItem::class);
    $valid = [
        'item_ref' => 'SKU-200',
        'description' => 'Steel Pipe',
        'warehouse_ref' => 'WH-EAST',
        'currency' => 'GBP',
        'valuation_method' => ValuationMethod::WeightedAverage->value,
    ];

    foreach (['item_ref', 'description', 'warehouse_ref', 'currency'] as $field) {
        expect(fn () => $create->handle(array_diff_key($valid, [$field => true])))
            ->toThrow(InvalidInventory::class, "Missing item field [{$field}].");
    }

    expect(fn () => $create->handle([...$valid, 'valuation_method' => 'lifo']))
        ->toThrow(InvalidInventory::class, 'A supported valuation method is required.');

    $item = $create->handle($valid);

    // ReceiveInventory validation
    $receive = app(ReceiveInventory::class);
    expect(fn () => $receive->handle($item, ['quantity' => 0, 'unit_cost' => 10, 'movement_ref' => 'R1', 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $receive->handle($item, ['quantity' => 5, 'unit_cost' => -1, 'movement_ref' => 'R1', 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $receive->handle($item, ['quantity' => 5, 'unit_cost' => 10, 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $receive->handle($item, ['quantity' => 5, 'unit_cost' => 10, 'movement_ref' => 'R1']))
        ->toThrow(InvalidInventory::class);

    // IssueInventory validation
    $issue = app(IssueInventory::class);
    expect(fn () => $issue->handle($item, ['quantity' => 0, 'movement_ref' => 'I1', 'source_ref' => 'SO1']))
        ->toThrow(InvalidInventory::class, 'positive quantity')
        ->and(fn () => $issue->handle($item, ['quantity' => 5, 'movement_ref' => '', 'source_ref' => 'SO1']))
        ->toThrow(InvalidInventory::class, 'positive quantity')
        ->and(fn () => $issue->handle($item, ['quantity' => 5, 'movement_ref' => 'I1', 'source_ref' => 'SO1']))
        ->toThrow(InvalidInventory::class, 'Insufficient quantity on hand.');

    // AdjustInventory validation
    $adjust = app(AdjustInventory::class);
    expect(fn () => $adjust->handle($item, ['adjustment_ref' => '', 'reason' => 'Fix', 'quantity_delta' => 1]))
        ->toThrow(InvalidInventory::class, 'non-zero delta')
        ->and(fn () => $adjust->handle($item, ['adjustment_ref' => 'A1', 'reason' => '', 'quantity_delta' => 1]))
        ->toThrow(InvalidInventory::class, 'non-zero delta')
        ->and(fn () => $adjust->handle($item, ['adjustment_ref' => 'A1', 'reason' => 'Zero', 'quantity_delta' => 0, 'value_delta' => 0]))
        ->toThrow(InvalidInventory::class, 'non-zero delta')
        ->and(fn () => $adjust->handle($item, ['adjustment_ref' => 'A1', 'reason' => 'Negative', 'quantity_delta' => -5, 'value_delta' => -50]))
        ->toThrow(InvalidInventory::class, 'cannot make stock negative');

    // Increase quantity_on_hand without cost layers to trigger "Cost layers cannot satisfy the issue."
    $adjust->handle($item, ['adjustment_ref' => 'A2', 'reason' => 'Unlayered stock', 'quantity_delta' => 5, 'value_delta' => 50]);
    $item->refresh();
    expect(fn () => $issue->handle($item, ['quantity' => 3, 'movement_ref' => 'I-UNLAYERED', 'source_ref' => 'SO-2']))
        ->toThrow(InvalidInventory::class, 'Cost layers cannot satisfy the issue.');

    // ApplyLandedCost validation
    $landed = app(ApplyLandedCost::class);
    expect(fn () => $landed->handle($item, ['amount' => 0, 'cost_ref' => 'LC1', 'allocation_basis' => 'qty', 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $landed->handle($item, ['amount' => 10, 'cost_ref' => '', 'allocation_basis' => 'qty', 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $landed->handle($item, ['amount' => 10, 'cost_ref' => 'LC1', 'allocation_basis' => '', 'source_ref' => 'S1']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $landed->handle($item, ['amount' => 10, 'cost_ref' => 'LC1', 'allocation_basis' => 'qty', 'source_ref' => '']))
        ->toThrow(InvalidInventory::class);

    // WriteDownInventory validation
    $writeDown = app(WriteDownInventory::class);
    expect(fn () => $writeDown->handle($item, ['amount' => 0, 'write_down_ref' => 'W1', 'reason' => 'Obsolescence']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $writeDown->handle($item, ['amount' => 10, 'write_down_ref' => '', 'reason' => 'Obsolescence']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $writeDown->handle($item, ['amount' => 10, 'write_down_ref' => 'W1', 'reason' => '']))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $writeDown->handle($item, ['amount' => 9999, 'write_down_ref' => 'W1', 'reason' => 'Too high']))
        ->toThrow(InvalidInventory::class);

    // ReconcileInventory validation
    $reconcile = app(ReconcileInventory::class);
    expect(fn () => $reconcile->handle(['period_ref' => '2026-01', 'subledger_value' => 10, 'general_ledger_value' => 10]))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $reconcile->handle(['reconciliation_ref' => 'R1', 'subledger_value' => 10, 'general_ledger_value' => 10]))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $reconcile->handle(['reconciliation_ref' => 'R1', 'period_ref' => '2026-01', 'subledger_value' => -1, 'general_ledger_value' => 10]))
        ->toThrow(InvalidInventory::class)
        ->and(fn () => $reconcile->handle(['reconciliation_ref' => 'R1', 'period_ref' => '2026-01', 'subledger_value' => 10, 'general_ledger_value' => -5]))
        ->toThrow(InvalidInventory::class);
});

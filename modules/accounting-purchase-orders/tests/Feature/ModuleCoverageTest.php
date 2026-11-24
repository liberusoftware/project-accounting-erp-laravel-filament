<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\PurchaseOrders\Actions\CreatePurchaseOrder;
use Liberu\Accounting\PurchaseOrders\Actions\RecordPurchaseOrderChange;
use Liberu\Accounting\PurchaseOrders\Actions\RecordPurchaseReceipt;
use Liberu\Accounting\PurchaseOrders\Actions\TransitionPurchaseOrder;
use Liberu\Accounting\PurchaseOrders\Enums\PurchaseOrderStatus;
use Liberu\Accounting\PurchaseOrders\Exceptions\InvalidPurchaseOrder;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseOrder;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseOrderChange;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseOrderLine;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseReceipt;
use Liberu\Accounting\PurchaseOrders\PurchaseOrdersServiceProvider;

it('boots the service provider and loads purchase order module tables', function (): void {
    expect(app()->getProvider(PurchaseOrdersServiceProvider::class))
        ->toBeInstanceOf(PurchaseOrdersServiceProvider::class);

    expect(Schema::hasTable('accounting_purchase_orders_module'))->toBeTrue()
        ->and(Schema::hasTable('accounting_purchase_order_lines'))->toBeTrue()
        ->and(Schema::hasTable('accounting_purchase_order_receipts'))->toBeTrue()
        ->and(Schema::hasTable('accounting_purchase_order_changes'))->toBeTrue();
});

it('exposes all purchase order status enum cases', function (): void {
    expect(array_map(fn (PurchaseOrderStatus $c): string => $c->value, PurchaseOrderStatus::cases()))->toBe([
        'draft',
        'pending_approval',
        'approved',
        'issued',
        'partially_received',
        'received',
        'closed',
        'cancelled',
    ]);
});

it('creates purchase orders with lines and validates line and header inputs', function (): void {
    $create = app(CreatePurchaseOrder::class);

    $order = $create->handle([
        'team_id' => 2,
        'supplier_ref' => 'SUP-100',
        'currency' => 'gbp',
        'order_date' => '2026-05-01',
        'expected_delivery_on' => '2026-05-15',
        'source_requisition_ref' => 'REQ-55',
        'notes' => 'Deliver to loading bay',
        'metadata' => ['priority' => 'standard'],
    ], [
        ['item_ref' => 'ITEM-A', 'description' => 'Laptop', 'quantity' => 2, 'unit_price' => 500.25, 'delivery_metadata' => ['warehouse' => 'LON']],
        ['item_ref' => 'ITEM-B', 'quantity' => 1, 'unit_price' => 0],
    ]);

    expect($order)->toBeInstanceOf(PurchaseOrder::class)
        ->and($order->currency)->toBe('GBP')
        ->and($order->order_number)->toStartWith('PO-')
        ->and((float) $order->total_amount)->toBe(1000.5)
        ->and($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->lines)->toHaveCount(2)
        ->and($order->lines->first())->toBeInstanceOf(PurchaseOrderLine::class)
        ->and($order->lines->first()->delivery_metadata)->toBe(['warehouse' => 'LON']);

    // Validation errors
    expect(fn () => $create->handle(['supplier_ref' => 'SUP-1', 'currency' => 'GBP'], [
        ['item_ref' => '', 'quantity' => 1, 'unit_price' => 10],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Each line requires item, positive quantity, and non-negative price.');

    expect(fn () => $create->handle(['supplier_ref' => 'SUP-1', 'currency' => 'GBP'], [
        ['item_ref' => 'ITEM-A', 'quantity' => 0, 'unit_price' => 10],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Each line requires item, positive quantity, and non-negative price.');

    expect(fn () => $create->handle(['supplier_ref' => 'SUP-1', 'currency' => 'GBP'], [
        ['item_ref' => 'ITEM-A', 'quantity' => 1, 'unit_price' => -5],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Each line requires item, positive quantity, and non-negative price.');

    expect(fn () => $create->handle(['supplier_ref' => '', 'currency' => 'GBP'], [
        ['item_ref' => 'ITEM-A', 'quantity' => 1, 'unit_price' => 10],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Supplier, currency, and positive order total are required.');

    expect(fn () => $create->handle(['supplier_ref' => 'SUP-1', 'currency' => ''], [
        ['item_ref' => 'ITEM-A', 'quantity' => 1, 'unit_price' => 10],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Supplier, currency, and positive order total are required.');

    expect(fn () => $create->handle(['supplier_ref' => 'SUP-1', 'currency' => 'GBP'], []))
        ->toThrow(InvalidPurchaseOrder::class, 'Supplier, currency, and positive order total are required.');
});

it('handles purchase order lifecycle transitions, receipts, and changes', function (): void {
    $create = app(CreatePurchaseOrder::class);
    $transition = app(TransitionPurchaseOrder::class);
    $receiptAction = app(RecordPurchaseReceipt::class);
    $changeAction = app(RecordPurchaseOrderChange::class);

    $order = $create->handle([
        'supplier_ref' => 'SUP-200',
        'order_number' => 'PO-CUSTOM-001',
        'currency' => 'USD',
    ], [
        ['item_ref' => 'SKU-1', 'quantity' => 5, 'unit_price' => 20],
        ['item_ref' => 'SKU-2', 'quantity' => 2, 'unit_price' => 50],
    ]);

    // Receipt before Issued is rejected
    expect(fn () => $receiptAction->handle($order, [
        'lines' => [['line_id' => $order->lines[0]->id, 'quantity' => 1]],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Only issued orders can receive goods.');

    // Invalid transition Draft -> Issued
    expect(fn () => $transition->handle($order, PurchaseOrderStatus::Issued))
        ->toThrow(InvalidPurchaseOrder::class, 'Cannot transition order from draft to issued.');

    // Draft -> PendingApproval -> Approved -> Issued (with commitment_ref)
    $order = $transition->handle($order, PurchaseOrderStatus::PendingApproval);
    $order = $transition->handle($order, PurchaseOrderStatus::Approved);
    $order = $transition->handle($order, PurchaseOrderStatus::Issued, ['commitment_ref' => 'CMT-10']);

    expect($order->status)->toBe(PurchaseOrderStatus::Issued)
        ->and($order->commitment_ref)->toBe('CMT-10');

    // Record versioned changes
    $change1 = $changeAction->handle($order, [
        'changes' => ['expected_delivery_on' => '2026-06-01'],
        'reason' => 'Supplier rescheduled',
        'actor_ref' => 'buyer@example.com',
        'approved_at' => now(),
        'metadata' => ['ticket' => 'CHG-1'],
    ]);
    $change2 = $changeAction->handle($order, [
        'changes' => ['notes' => 'Updated dock'],
        'reason' => 'Warehouse update',
    ]);

    expect($change1)->toBeInstanceOf(PurchaseOrderChange::class)
        ->and($change1->version)->toBe(1)
        ->and($change2->version)->toBe(2)
        ->and($order->changes()->count())->toBe(2);

    // Invalid change payloads
    expect(fn () => $changeAction->handle($order, ['changes' => ['a' => 1], 'reason' => '']))
        ->toThrow(InvalidPurchaseOrder::class, 'Closed orders cannot receive changes and changes require a reason.');
    expect(fn () => $changeAction->handle($order, ['changes' => 'invalid', 'reason' => 'Fix']))
        ->toThrow(InvalidPurchaseOrder::class, 'Closed orders cannot receive changes and changes require a reason.');

    // Receipt validation errors
    $line1 = $order->lines[0];
    $line2 = $order->lines[1];

    expect(fn () => $receiptAction->handle($order, [
        'lines' => [['line_id' => 999999, 'quantity' => 1]],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Receipt exceeds remaining ordered quantity.');

    expect(fn () => $receiptAction->handle($order, [
        'lines' => [['line_id' => $line1->id, 'quantity' => 0]],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Receipt exceeds remaining ordered quantity.');

    expect(fn () => $receiptAction->handle($order, [
        'lines' => [['line_id' => $line1->id, 'quantity' => 6]],
    ]))->toThrow(InvalidPurchaseOrder::class, 'Receipt exceeds remaining ordered quantity.');

    // Partial receipt transitions order to PartiallyReceived
    $grn1 = $receiptAction->handle($order, [
        'receipt_ref' => 'GRN-PART-1',
        'received_on' => '2026-05-10',
        'document_ref' => 'DOC-1',
        'lines' => [['line_id' => $line1->id, 'quantity' => 3]],
    ]);

    $order->refresh();
    expect($grn1)->toBeInstanceOf(PurchaseReceipt::class)
        ->and($grn1->status)->toBe('accepted')
        ->and($order->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $line1->refresh()->received_quantity)->toBe(3.0);

    // Remaining receipt transitions order to Received
    $grn2 = $receiptAction->handle($order, [
        'lines' => [
            ['line_id' => $line1->id, 'quantity' => 2],
            ['line_id' => $line2->id, 'quantity' => 2],
        ],
    ]);

    $order->refresh();
    expect($grn2->receipt_ref)->toStartWith('GRN-'.$order->id.'-')
        ->and($order->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->receipts)->toHaveCount(2);

    // Close Received order; closed order rejects changes and further transitions
    $order = $transition->handle($order, PurchaseOrderStatus::Closed);
    expect($order->status)->toBe(PurchaseOrderStatus::Closed);

    expect(fn () => $changeAction->handle($order, ['changes' => ['notes' => 'Late'], 'reason' => 'Test']))
        ->toThrow(InvalidPurchaseOrder::class, 'Closed orders cannot receive changes and changes require a reason.');

    expect(fn () => $transition->handle($order, PurchaseOrderStatus::Draft))
        ->toThrow(InvalidPurchaseOrder::class, 'Cannot transition order from closed to draft.');

    // Also cover Cancel paths & Issued/PartiallyReceived -> Closed
    $toCancel = $create->handle(['supplier_ref' => 'SUP-3', 'currency' => 'EUR'], [['item_ref' => 'X', 'quantity' => 1, 'unit_price' => 10]]);
    $toCancel = $transition->handle($toCancel, PurchaseOrderStatus::Cancelled);
    expect($toCancel->status)->toBe(PurchaseOrderStatus::Cancelled);
    expect(fn () => $changeAction->handle($toCancel, ['changes' => ['x' => 1], 'reason' => 'Nope']))
        ->toThrow(InvalidPurchaseOrder::class);

    $closeIssued = $create->handle(['supplier_ref' => 'SUP-4', 'currency' => 'EUR'], [['item_ref' => 'Y', 'quantity' => 1, 'unit_price' => 10]]);
    $closeIssued = $transition->handle($closeIssued, PurchaseOrderStatus::PendingApproval);
    $closeIssued = $transition->handle($closeIssued, PurchaseOrderStatus::Approved);
    $closeIssued = $transition->handle($closeIssued, PurchaseOrderStatus::Issued);
    expect($transition->handle($closeIssued, PurchaseOrderStatus::Closed)->status)->toBe(PurchaseOrderStatus::Closed);
});

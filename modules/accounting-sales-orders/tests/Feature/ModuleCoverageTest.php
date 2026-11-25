<?php

declare(strict_types=1);

use Liberu\Accounting\SalesOrders\Actions\AddFulfillmentReference;
use Liberu\Accounting\SalesOrders\Actions\AddSalesOrderDeposit;
use Liberu\Accounting\SalesOrders\Actions\CreateSalesOrder;
use Liberu\Accounting\SalesOrders\Actions\RecordPartialInvoice;
use Liberu\Accounting\SalesOrders\Actions\TransitionSalesOrder;
use Liberu\Accounting\SalesOrders\Enums\OrderStatus;
use Liberu\Accounting\SalesOrders\Exceptions\InvalidSalesOrder;
use Liberu\Accounting\SalesOrders\Models\SalesOrder;
use Liberu\Accounting\SalesOrders\Models\SalesOrderAllocation;
use Liberu\Accounting\SalesOrders\Models\SalesOrderDeposit;
use Liberu\Accounting\SalesOrders\Models\SalesOrderItem;
use Liberu\Accounting\SalesOrders\Queries\SalesOrderQuery;
use Liberu\Accounting\SalesOrders\SalesOrdersServiceProvider;

it('boots sales orders service provider and covers enum and exception', function (): void {
    $provider = $this->app->register(SalesOrdersServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SalesOrdersServiceProvider::class)
        ->and(array_column(OrderStatus::cases(), 'value'))->toBe(['draft', 'confirmed', 'partially_invoiced', 'invoiced', 'cancelled'])
        ->and((new InvalidSalesOrder('Order error'))->getMessage())->toBe('Order error');
});

it('executes sales order creation, transitions, deposits, fulfillment allocations, partial invoicing, and queries', function (): void {
    $query = app(SalesOrderQuery::class);
    expect($query->paginate()->total())->toBe(0);

    $order = app(CreateSalesOrder::class)->handle([
        'team_id' => 7,
        'customer_id' => 'cust-100',
        'estimate_id' => 'est-55',
        'order_date' => '2026-08-25',
        'currency' => 'USD',
        'notes' => 'Priority order',
        'metadata' => ['channel' => 'web'],
    ], [
        [
            'sku' => 'SKU-A',
            'description' => 'Widget A',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ],
        [
            'sku' => 'SKU-B',
            'description' => 'Widget B',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ],
    ]);

    expect($order)->toBeInstanceOf(SalesOrder::class)
        ->and($order->order_number)->toStartWith('SO-')
        ->and($order->status)->toBe(OrderStatus::Draft)
        ->and((float) $order->subtotal)->toBe(250.00)
        ->and((float) $order->tax_total)->toBe(20.00)
        ->and((float) $order->total)->toBe(270.00)
        ->and($order->items)->toHaveCount(2);

    $firstItem = $order->items->first();
    expect($firstItem)->toBeInstanceOf(SalesOrderItem::class)
        ->and($firstItem->order->is($order))->toBeTrue();

    // Confirm via string status
    $confirmed = app(TransitionSalesOrder::class)->handle($order, 'confirmed');
    expect($confirmed->status)->toBe(OrderStatus::Confirmed);

    // Add deposit
    $deposit = app(AddSalesOrderDeposit::class)->handle($confirmed, [
        'reference' => 'DEP-SO-1',
        'amount' => 100.00,
        'currency' => 'USD',
        'status' => 'received',
        'metadata' => ['method' => 'bank'],
    ]);

    expect($deposit)->toBeInstanceOf(SalesOrderDeposit::class)
        ->and((float) $deposit->amount)->toBe(100.00)
        ->and($deposit->order->is($confirmed))->toBeTrue()
        ->and($confirmed->deposits()->count())->toBe(1);

    // Add fulfillment reference
    $allocation = app(AddFulfillmentReference::class)->handle($confirmed, [
        'item_id' => $firstItem->id,
        'fulfillment_type' => 'shipment',
        'fulfillment_id' => 'SHP-900',
        'quantity' => 2,
        'status' => 'reserved',
        'metadata' => ['warehouse' => 'LON-1'],
    ]);

    expect($allocation)->toBeInstanceOf(SalesOrderAllocation::class)
        ->and((float) $allocation->quantity)->toBe(2.0)
        ->and($allocation->order->is($confirmed))->toBeTrue()
        ->and($confirmed->allocations()->count())->toBe(1);

    // Partial invoice then final invoice
    $partial = app(RecordPartialInvoice::class)->handle($confirmed, 100.00, 'INV-1');
    expect($partial->status)->toBe(OrderStatus::PartiallyInvoiced)
        ->and((float) $partial->invoiced_total)->toBe(100.00)
        ->and($partial->metadata['invoice_references'])->toBe(['INV-1']);

    $invoiced = app(RecordPartialInvoice::class)->handle($partial, 170.00, 'INV-2');
    expect($invoiced->status)->toBe(OrderStatus::Invoiced)
        ->and((float) $invoiced->invoiced_total)->toBe(270.00)
        ->and($invoiced->metadata['invoice_references'])->toBe(['INV-1', 'INV-2']);

    // Create a second order with explicit order_number and cancel it via enum
    $cancelledOrder = app(CreateSalesOrder::class)->handle([
        'team_id' => 8,
        'customer_id' => 'cust-200',
        'order_number' => 'SO-CUSTOM-1',
        'order_date' => '2026-08-26',
        'currency' => 'GBP',
    ], [
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 40],
    ]);

    $cancelled = app(TransitionSalesOrder::class)->handle($cancelledOrder, OrderStatus::Cancelled);
    expect($cancelled->status)->toBe(OrderStatus::Cancelled);

    // Query filters and perPage clamping
    $filtered = $query->paginate('cust-100', 'invoiced', 200, 7);
    expect($filtered->total())->toBe(1)
        ->and($filtered->perPage())->toBe(100);

    $clampedMin = $query->paginate('cust-200', 'cancelled', 0, 8);
    expect($clampedMin->total())->toBe(1)
        ->and($clampedMin->perPage())->toBe(1);
});

it('enforces validation and state transition rules across sales order actions', function (): void {
    $create = app(CreateSalesOrder::class);
    $validAttrs = [
        'customer_id' => 'cust-val',
        'currency' => 'USD',
        'order_date' => '2026-08-25',
    ];
    $validLines = [['description' => 'Item', 'quantity' => 2, 'unit_price' => 50]];

    foreach (['customer_id', 'currency', 'order_date'] as $required) {
        expect(fn () => $create->handle(array_diff_key($validAttrs, [$required => true]), $validLines))
            ->toThrow(InvalidSalesOrder::class, "Sales order field [{$required}] is required.");
    }

    expect(fn () => $create->handle($validAttrs, []))
        ->toThrow(InvalidSalesOrder::class, 'A sales order requires at least one line.')
        ->and(fn () => $create->handle($validAttrs, [['description' => 'Bad qty', 'quantity' => 0, 'unit_price' => 10]]))
        ->toThrow(InvalidSalesOrder::class, 'Order quantities must be positive and prices non-negative.')
        ->and(fn () => $create->handle($validAttrs, [['description' => 'Bad price', 'quantity' => 1, 'unit_price' => -5]]))
        ->toThrow(InvalidSalesOrder::class, 'Order quantities must be positive and prices non-negative.');

    $draft = $create->handle($validAttrs, $validLines);

    $transition = app(TransitionSalesOrder::class);
    expect(fn () => $transition->handle($draft, 'unknown_status'))
        ->toThrow(InvalidSalesOrder::class, 'Unknown sales order status.')
        ->and(fn () => $transition->handle($draft, OrderStatus::Invoiced))
        ->toThrow(InvalidSalesOrder::class, 'Cannot transition draft to invoiced.');

    $addDeposit = app(AddSalesOrderDeposit::class);
    expect(fn () => $addDeposit->handle($draft, ['amount' => 0, 'reference' => 'DEP-0', 'currency' => 'USD']))
        ->toThrow(InvalidSalesOrder::class, 'A deposit requires a positive amount and reference.')
        ->and(fn () => $addDeposit->handle($draft, ['amount' => 10, 'reference' => '', 'currency' => 'USD']))
        ->toThrow(InvalidSalesOrder::class, 'A deposit requires a positive amount and reference.')
        ->and(fn () => $addDeposit->handle($draft, ['amount' => 150, 'reference' => 'DEP-OVER', 'currency' => 'USD']))
        ->toThrow(InvalidSalesOrder::class, 'Deposits cannot exceed the order total.');

    $addFulfillment = app(AddFulfillmentReference::class);
    foreach (['fulfillment_type', 'fulfillment_id', 'quantity'] as $required) {
        $payload = ['fulfillment_type' => 'shipment', 'fulfillment_id' => 'SHP-1', 'quantity' => 1];
        expect(fn () => $addFulfillment->handle($draft, array_diff_key($payload, [$required => true])))
            ->toThrow(InvalidSalesOrder::class, "Fulfillment field [{$required}] is required.");
    }

    expect(fn () => $addFulfillment->handle($draft, ['fulfillment_type' => 'shipment', 'fulfillment_id' => 'SHP-1', 'quantity' => 0]))
        ->toThrow(InvalidSalesOrder::class, 'Fulfillment quantity must be positive.');

    $recordInvoice = app(RecordPartialInvoice::class);
    expect(fn () => $recordInvoice->handle($draft, 0, 'INV-0'))
        ->toThrow(InvalidSalesOrder::class, 'An invoice requires a positive amount and reference.')
        ->and(fn () => $recordInvoice->handle($draft, 10, ''))
        ->toThrow(InvalidSalesOrder::class, 'An invoice requires a positive amount and reference.')
        ->and(fn () => $recordInvoice->handle($draft, 10, 'INV-DRAFT'))
        ->toThrow(InvalidSalesOrder::class, 'This order cannot accept the requested invoice amount.');

    $confirmed = $transition->handle($draft, OrderStatus::Confirmed);
    expect(fn () => $recordInvoice->handle($confirmed, 200, 'INV-OVER'))
        ->toThrow(InvalidSalesOrder::class, 'This order cannot accept the requested invoice amount.');
});

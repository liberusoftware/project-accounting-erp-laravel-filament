<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\AddReceiptAttachment;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\AddReceiptLine;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\ConfirmService;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\CreateReceipt;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\PostAccrual;
use Liberu\Accounting\GoodsAndServiceReceipts\Actions\ReturnReceipt;
use Liberu\Accounting\GoodsAndServiceReceipts\Enums\ReceiptStatus;
use Liberu\Accounting\GoodsAndServiceReceipts\Enums\ReceiptType;
use Liberu\Accounting\GoodsAndServiceReceipts\Events\ReceiptAccrualPosted;
use Liberu\Accounting\GoodsAndServiceReceipts\Exceptions\InvalidReceipt;
use Liberu\Accounting\GoodsAndServiceReceipts\GoodsAndServiceReceiptsServiceProvider;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\Receipt;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\ReceiptAccrual;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\ReceiptAttachment;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\ReceiptLine;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\ReceiptReturn;
use Liberu\Accounting\GoodsAndServiceReceipts\Models\ServiceConfirmation;
use Liberu\Accounting\GoodsAndServiceReceipts\Queries\ReceiptQuery;

it('registers and boots the goods and service receipts service provider and migrations', function (): void {
    $provider = app()->getProvider(GoodsAndServiceReceiptsServiceProvider::class)
        ?? new GoodsAndServiceReceiptsServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(GoodsAndServiceReceiptsServiceProvider::class)
        ->and(app(ReceiptQuery::class))->toBe(app(ReceiptQuery::class))
        ->and(Schema::hasTable('accounting_goods_service_receipts'))->toBeTrue()
        ->and(Schema::hasTable('accounting_goods_service_receipt_lines'))->toBeTrue()
        ->and(Schema::hasTable('accounting_goods_service_confirmations'))->toBeTrue()
        ->and(Schema::hasTable('accounting_goods_service_receipt_returns'))->toBeTrue()
        ->and(Schema::hasTable('accounting_goods_service_receipt_attachments'))->toBeTrue()
        ->and(Schema::hasTable('accounting_goods_service_receipt_accruals'))->toBeTrue();
});

it('exposes receipt enums, exception class, and accrual posted event', function (): void {
    expect(array_map(static fn (ReceiptStatus $s): string => $s->value, ReceiptStatus::cases()))
        ->toBe(['draft', 'received', 'confirmed', 'partially_returned', 'closed'])
        ->and(array_map(static fn (ReceiptType $t): string => $t->value, ReceiptType::cases()))
        ->toBe(['goods', 'service']);

    $exception = new InvalidReceipt('Receipt error.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Receipt error.');

    $receipt = app(CreateReceipt::class)->handle([
        'team_id' => 1,
        'receipt_ref' => 'GRN-EVT-1',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-1',
        'currency' => 'gbp',
    ]);

    $accrual = app(PostAccrual::class)->handle($receipt, [
        'accrual_ref' => 'ACC-EVT-1',
        'amount' => 150.25,
        'period_ref' => '2026-08',
        'source_ref' => 'SRC-1',
    ]);

    Event::fake([ReceiptAccrualPosted::class]);
    ReceiptAccrualPosted::dispatch($accrual);

    Event::assertDispatched(ReceiptAccrualPosted::class, function (ReceiptAccrualPosted $event) use ($accrual): bool {
        return $event->accrual->is($accrual);
    });
});

it('creates receipts and validates required fields and receipt type', function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');
    $action = app(CreateReceipt::class);

    $receipt = $action->handle([
        'team_id' => 10,
        'receipt_ref' => 'GRN-100',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-100',
        'purchase_order_ref' => 'PO-100',
        'currency' => 'usd',
        'inventory_ref' => 'WH-MAIN',
        'project_ref' => 'PRJ-1',
        'metadata' => ['dock' => 'A1'],
    ]);

    expect($receipt)->toBeInstanceOf(Receipt::class)
        ->and($receipt->team_id)->toBe(10)
        ->and($receipt->receipt_ref)->toBe('GRN-100')
        ->and($receipt->receipt_type)->toBe(ReceiptType::Goods)
        ->and($receipt->status)->toBe(ReceiptStatus::Draft)
        ->and($receipt->currency)->toBe('USD')
        ->and($receipt->received_at->toDateTimeString())->toBe('2026-08-29 10:00:00')
        ->and((float) $receipt->total_value)->toBe(0.0)
        ->and($receipt->metadata)->toBe(['dock' => 'A1']);

    Carbon::setTestNow();
});

it('rejects invalid create receipt payloads', function (array $override, string $message): void {
    $base = [
        'team_id' => 10,
        'receipt_ref' => 'GRN-ERR',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-1',
        'currency' => 'GBP',
    ];
    $payload = array_merge($base, $override);

    expect(fn () => app(CreateReceipt::class)->handle($payload))
        ->toThrow(InvalidReceipt::class, $message);
})->with([
    'missing receipt_ref' => [['receipt_ref' => ''], 'Missing receipt field [receipt_ref].'],
    'missing supplier_ref' => [['supplier_ref' => ''], 'Missing receipt field [supplier_ref].'],
    'missing currency' => [['currency' => ''], 'Missing receipt field [currency].'],
    'invalid receipt_type' => [['receipt_type' => 'invalid'], 'Receipt type must be goods or service.'],
]);

it('adds receipt lines, computes variance, updates totals, and validates errors', function (): void {
    $receipt = app(CreateReceipt::class)->handle([
        'team_id' => 11,
        'receipt_ref' => 'GRN-LINE-1',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-11',
        'currency' => 'EUR',
        'inventory_ref' => 'INV-DEFAULT',
        'project_ref' => 'PRJ-DEFAULT',
    ]);

    $addLine = app(AddReceiptLine::class);

    $line1 = $addLine->handle($receipt, [
        'line_ref' => 'L-1',
        'item_ref' => 'SKU-1',
        'description' => 'Steel Bolts',
        'ordered_quantity' => 10,
        'received_quantity' => 12,
        'unit_price' => 5.5,
        'metadata' => ['bin' => 'B2'],
    ]);

    $line2 = $addLine->handle($receipt, [
        'line_ref' => 'L-2',
        'description' => 'Washers',
        'received_quantity' => 4,
        'unit_price' => 2.0,
        'inventory_ref' => 'INV-CUSTOM',
        'project_ref' => 'PRJ-CUSTOM',
    ]);

    $receipt->refresh();

    expect($line1)->toBeInstanceOf(ReceiptLine::class)
        ->and($line1->receipt->is($receipt))->toBeTrue()
        ->and((float) $line1->line_value)->toBe(66.0)
        ->and((float) $line1->variance_quantity)->toBe(2.0)
        ->and((float) $line1->variance_value)->toBe(11.0)
        ->and($line1->inventory_ref)->toBe('INV-DEFAULT')
        ->and($line1->project_ref)->toBe('PRJ-DEFAULT')
        ->and($line1->metadata)->toBe(['bin' => 'B2'])
        ->and((float) $line2->variance_quantity)->toBe(0.0)
        ->and($line2->inventory_ref)->toBe('INV-CUSTOM')
        ->and($line2->project_ref)->toBe('PRJ-CUSTOM')
        ->and((float) $receipt->total_value)->toBe(74.0)
        ->and($receipt->lines)->toHaveCount(2);

    expect(fn () => $addLine->handle($receipt, ['description' => 'X', 'received_quantity' => 1, 'unit_price' => 1]))
        ->toThrow(InvalidReceipt::class, 'Missing line field [line_ref].');
    expect(fn () => $addLine->handle($receipt, ['line_ref' => 'L-3', 'received_quantity' => 1, 'unit_price' => 1]))
        ->toThrow(InvalidReceipt::class, 'Missing line field [description].');
    expect(fn () => $addLine->handle($receipt, ['line_ref' => 'L-3', 'description' => 'X', 'received_quantity' => 0, 'unit_price' => 1]))
        ->toThrow(InvalidReceipt::class, 'Receipt quantities and price are invalid.');
    expect(fn () => $addLine->handle($receipt, ['line_ref' => 'L-3', 'description' => 'X', 'received_quantity' => 1, 'unit_price' => -1]))
        ->toThrow(InvalidReceipt::class, 'Receipt quantities and price are invalid.');
    expect(fn () => $addLine->handle($receipt, ['line_ref' => 'L-3', 'description' => 'X', 'ordered_quantity' => -2, 'received_quantity' => 1, 'unit_price' => 1]))
        ->toThrow(InvalidReceipt::class, 'Receipt quantities and price are invalid.');

    $receipt->update(['status' => ReceiptStatus::Received]);
    expect(fn () => $addLine->handle($receipt, ['line_ref' => 'L-3', 'description' => 'X', 'received_quantity' => 1, 'unit_price' => 1]))
        ->toThrow(InvalidReceipt::class, 'Lines can only be added to draft receipts.');
});

it('confirms service receipts and rejects invalid confirmations', function (): void {
    $create = app(CreateReceipt::class);
    $confirm = app(ConfirmService::class);

    $goodsReceipt = $create->handle([
        'team_id' => 12,
        'receipt_ref' => 'GRN-GOODS-12',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-12',
        'currency' => 'GBP',
    ]);

    expect(fn () => $confirm->handle($goodsReceipt, [
        'confirmation_ref' => 'CNF-1',
        'service_period' => '2026-08',
        'quantity' => 1,
        'value' => 100,
        'confirmed_by' => 'alice',
    ]))->toThrow(InvalidReceipt::class, 'Only open service receipts can be confirmed.');

    $serviceReceipt = $create->handle([
        'team_id' => 12,
        'receipt_ref' => 'SRN-12',
        'receipt_type' => 'service',
        'supplier_ref' => 'SUP-12',
        'currency' => 'GBP',
    ]);

    expect(fn () => $confirm->handle($serviceReceipt, ['service_period' => '2026-08', 'confirmed_by' => 'alice', 'quantity' => 1]))
        ->toThrow(InvalidReceipt::class, 'Missing confirmation field [confirmation_ref].');
    expect(fn () => $confirm->handle($serviceReceipt, ['confirmation_ref' => 'CNF-1', 'confirmed_by' => 'alice', 'quantity' => 1]))
        ->toThrow(InvalidReceipt::class, 'Missing confirmation field [service_period].');
    expect(fn () => $confirm->handle($serviceReceipt, ['confirmation_ref' => 'CNF-1', 'service_period' => '2026-08', 'quantity' => 1]))
        ->toThrow(InvalidReceipt::class, 'Missing confirmation field [confirmed_by].');
    expect(fn () => $confirm->handle($serviceReceipt, ['confirmation_ref' => 'CNF-1', 'service_period' => '2026-08', 'confirmed_by' => 'alice', 'quantity' => 0, 'value' => 10]))
        ->toThrow(InvalidReceipt::class, 'Service confirmation quantities and value are invalid.');
    expect(fn () => $confirm->handle($serviceReceipt, ['confirmation_ref' => 'CNF-1', 'service_period' => '2026-08', 'confirmed_by' => 'alice', 'quantity' => 1, 'value' => -5]))
        ->toThrow(InvalidReceipt::class, 'Service confirmation quantities and value are invalid.');

    $confirmation = $confirm->handle($serviceReceipt, [
        'confirmation_ref' => 'CNF-1',
        'service_period' => '2026-08',
        'quantity' => 8,
        'value' => 800,
        'confirmed_by' => 'alice',
        'comment' => 'Delivered on time',
    ]);

    $serviceReceipt->refresh();
    expect($confirmation)->toBeInstanceOf(ServiceConfirmation::class)
        ->and($confirmation->receipt->is($serviceReceipt))->toBeTrue()
        ->and((float) $confirmation->quantity)->toBe(8.0)
        ->and((float) $confirmation->value)->toBe(800.0)
        ->and($serviceReceipt->status)->toBe(ReceiptStatus::Confirmed)
        ->and($serviceReceipt->confirmations)->toHaveCount(1);

    $serviceReceipt->update(['status' => ReceiptStatus::Closed]);
    expect(fn () => $confirm->handle($serviceReceipt, [
        'confirmation_ref' => 'CNF-2',
        'service_period' => '2026-09',
        'quantity' => 1,
        'value' => 100,
        'confirmed_by' => 'alice',
    ]))->toThrow(InvalidReceipt::class, 'Only open service receipts can be confirmed.');
});

it('handles receipt returns, attachments, and accruals with validation', function (): void {
    $receipt = app(CreateReceipt::class)->handle([
        'team_id' => 13,
        'receipt_ref' => 'GRN-13',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-13',
        'currency' => 'gbp',
    ]);

    $line = app(AddReceiptLine::class)->handle($receipt, [
        'line_ref' => 'LINE-A',
        'description' => 'Widgets',
        'ordered_quantity' => 10,
        'received_quantity' => 10,
        'unit_price' => 20,
    ]);

    $returnAction = app(ReturnReceipt::class);
    foreach (['return_ref', 'line_ref', 'reason', 'source_ref'] as $missing) {
        $payload = [
            'return_ref' => 'RET-1',
            'line_ref' => 'LINE-A',
            'reason' => 'Damaged',
            'source_ref' => 'RMA-1',
            'quantity' => 2,
            'value' => 40,
        ];
        unset($payload[$missing]);
        expect(fn () => $returnAction->handle($receipt, $payload))
            ->toThrow(InvalidReceipt::class, "Missing return field [{$missing}].");
    }

    expect(fn () => $returnAction->handle($receipt, [
        'return_ref' => 'RET-1', 'line_ref' => 'UNKNOWN', 'reason' => 'Damaged', 'source_ref' => 'RMA-1', 'quantity' => 1, 'value' => 20,
    ]))->toThrow(InvalidReceipt::class, 'Return exceeds the received quantity or is invalid.');

    expect(fn () => $returnAction->handle($receipt, [
        'return_ref' => 'RET-1', 'line_ref' => 'LINE-A', 'reason' => 'Damaged', 'source_ref' => 'RMA-1', 'quantity' => 0, 'value' => 20,
    ]))->toThrow(InvalidReceipt::class, 'Return exceeds the received quantity or is invalid.');

    expect(fn () => $returnAction->handle($receipt, [
        'return_ref' => 'RET-1', 'line_ref' => 'LINE-A', 'reason' => 'Damaged', 'source_ref' => 'RMA-1', 'quantity' => 11, 'value' => 220,
    ]))->toThrow(InvalidReceipt::class, 'Return exceeds the received quantity or is invalid.');

    expect(fn () => $returnAction->handle($receipt, [
        'return_ref' => 'RET-1', 'line_ref' => 'LINE-A', 'reason' => 'Damaged', 'source_ref' => 'RMA-1', 'quantity' => 2, 'value' => -1,
    ]))->toThrow(InvalidReceipt::class, 'Return exceeds the received quantity or is invalid.');

    $return = $returnAction->handle($receipt, [
        'return_ref' => 'RET-1',
        'line_ref' => 'LINE-A',
        'reason' => 'Damaged',
        'source_ref' => 'RMA-1',
        'quantity' => 3,
        'value' => 60,
    ]);

    $line->refresh();
    $receipt->refresh();
    expect($return)->toBeInstanceOf(ReceiptReturn::class)
        ->and($return->receipt->is($receipt))->toBeTrue()
        ->and((float) $line->returned_quantity)->toBe(3.0)
        ->and($receipt->status)->toBe(ReceiptStatus::PartiallyReturned)
        ->and($receipt->returns)->toHaveCount(1);

    // Attachments
    $attachAction = app(AddReceiptAttachment::class);
    foreach (['attachment_ref', 'kind', 'file_ref', 'attached_by'] as $missing) {
        $payload = [
            'attachment_ref' => 'ATT-1',
            'kind' => 'delivery_note',
            'file_ref' => 'files/dn.pdf',
            'attached_by' => 'bob',
        ];
        unset($payload[$missing]);
        expect(fn () => $attachAction->handle($receipt, $payload))
            ->toThrow(InvalidReceipt::class, "Missing attachment field [{$missing}].");
    }

    $attachment = $attachAction->handle($receipt, [
        'attachment_ref' => 'ATT-1',
        'kind' => 'delivery_note',
        'file_ref' => 'files/dn.pdf',
        'description' => 'Signed note',
        'checksum' => 'sha256:abc',
        'attached_by' => 'bob',
        'metadata' => ['pages' => 2],
    ]);

    expect($attachment)->toBeInstanceOf(ReceiptAttachment::class)
        ->and($attachment->receipt->is($receipt))->toBeTrue()
        ->and($attachment->metadata)->toBe(['pages' => 2])
        ->and($receipt->attachments)->toHaveCount(1);

    // Accruals
    $accrualAction = app(PostAccrual::class);
    foreach (['accrual_ref', 'period_ref', 'source_ref'] as $missing) {
        $payload = [
            'accrual_ref' => 'ACC-1',
            'period_ref' => '2026-08',
            'source_ref' => 'JRN-1',
            'amount' => 140,
        ];
        unset($payload[$missing]);
        expect(fn () => $accrualAction->handle($receipt, $payload))
            ->toThrow(InvalidReceipt::class, "Missing accrual field [{$missing}].");
    }

    expect(fn () => $accrualAction->handle($receipt, [
        'accrual_ref' => 'ACC-1', 'period_ref' => '2026-08', 'source_ref' => 'JRN-1', 'amount' => 0,
    ]))->toThrow(InvalidReceipt::class, 'Accrual amount must be positive.');

    $accrual = $accrualAction->handle($receipt, [
        'accrual_ref' => 'ACC-1',
        'period_ref' => '2026-08',
        'source_ref' => 'JRN-1',
        'amount' => 140,
        'metadata' => ['auto' => true],
    ]);

    expect($accrual)->toBeInstanceOf(ReceiptAccrual::class)
        ->and($accrual->receipt->is($receipt))->toBeTrue()
        ->and($accrual->currency)->toBe('GBP')
        ->and($accrual->status)->toBe('posted')
        ->and((float) $accrual->amount)->toBe(140.0)
        ->and($accrual->metadata)->toBe(['auto' => true])
        ->and($receipt->accruals)->toHaveCount(1);
});

it('queries paginated receipts and computes receipt variance', function (): void {
    $query = app(ReceiptQuery::class);
    expect($query->paginate(999)->total())->toBe(0);

    $create = app(CreateReceipt::class);
    $addLine = app(AddReceiptLine::class);

    $r1 = $create->handle([
        'team_id' => 20,
        'receipt_ref' => 'GRN-Q1',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-Q',
        'currency' => 'USD',
        'received_at' => '2026-08-01 10:00:00',
    ]);
    $addLine->handle($r1, [
        'line_ref' => 'QL-1',
        'description' => 'Item 1',
        'ordered_quantity' => 10,
        'received_quantity' => 12,
        'unit_price' => 10,
    ]);
    $addLine->handle($r1, [
        'line_ref' => 'QL-2',
        'description' => 'Item 2',
        'ordered_quantity' => 5,
        'received_quantity' => 4,
        'unit_price' => 5,
    ]);

    $r2 = $create->handle([
        'team_id' => 20,
        'receipt_ref' => 'SRN-Q2',
        'receipt_type' => 'service',
        'supplier_ref' => 'SUP-Q',
        'currency' => 'USD',
        'received_at' => '2026-08-02 10:00:00',
    ]);
    app(ConfirmService::class)->handle($r2, [
        'confirmation_ref' => 'CNF-Q',
        'service_period' => '2026-08',
        'quantity' => 1,
        'value' => 500,
        'confirmed_by' => 'ops',
    ]);

    $create->handle([
        'team_id' => 21,
        'receipt_ref' => 'GRN-Q3',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-OTHER',
        'currency' => 'USD',
    ]);

    $page = $query->paginate(20, null, 200);
    expect($page->total())->toBe(2)
        ->and($page->perPage())->toBe(100)
        ->and($page->items()[0]->receipt_ref)->toBe('SRN-Q2');

    $confirmedOnly = $query->paginate(20, ReceiptStatus::Confirmed, 0);
    expect($confirmedOnly->total())->toBe(1)
        ->and($confirmedOnly->perPage())->toBe(1)
        ->and($confirmedOnly->items()[0]->receipt_ref)->toBe('SRN-Q2');

    $variance = $query->variance($r1);
    expect($variance['quantity_variance'])->toBe(1.0)
        ->and($variance['value_variance'])->toBe(15.0)
        ->and($variance['lines'])->toHaveCount(2)
        ->and($variance['lines'][0]['line_ref'])->toBe('QL-1');
});

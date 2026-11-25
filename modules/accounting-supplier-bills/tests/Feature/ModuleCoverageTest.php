<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Accounting\AccountsPayable\Models\PayableOpenItem;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SupplierBills\Actions\AddSupplierBillCredit;
use Liberu\Accounting\SupplierBills\Actions\ApproveSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\AttachSupplierBillDocument;
use Liberu\Accounting\SupplierBills\Actions\CreateSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\MatchSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\PostSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\RejectSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\UpdateSupplierBill;
use Liberu\Accounting\SupplierBills\Actions\VoidSupplierBill;
use Liberu\Accounting\SupplierBills\Enums\PaymentStatus;
use Liberu\Accounting\SupplierBills\Enums\SupplierBillStatus;
use Liberu\Accounting\SupplierBills\Events\SupplierBillApproved;
use Liberu\Accounting\SupplierBills\Events\SupplierBillCreated;
use Liberu\Accounting\SupplierBills\Events\SupplierBillPosted;
use Liberu\Accounting\SupplierBills\Exceptions\InvalidSupplierBill;
use Liberu\Accounting\SupplierBills\Models\SupplierBill;
use Liberu\Accounting\SupplierBills\Models\SupplierBillCredit;
use Liberu\Accounting\SupplierBills\Models\SupplierBillDocument;
use Liberu\Accounting\SupplierBills\Models\SupplierBillLine;
use Liberu\Accounting\SupplierBills\Models\SupplierBillMatch;
use Liberu\Accounting\SupplierBills\Queries\DuplicateSupplierBillQuery;
use Liberu\Accounting\SupplierBills\Queries\SupplierBillAgingQuery;
use Liberu\Accounting\SupplierBills\SupplierBillsServiceProvider;

$createSupplierParty = static function (string $name = 'Acme Supplies', PartyType $type = PartyType::Supplier): Party {
    $entity = LegalEntity::query()->first() ?? LegalEntity::create([
        'name' => 'Default Corp',
        'currency_code' => 'USD',
    ]);

    return Party::create([
        'legal_entity_id' => $entity->id,
        'type' => $type,
        'name' => $name,
    ]);
};

it('boots supplier bills service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(SupplierBillsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SupplierBillsServiceProvider::class)
        ->and(array_column(SupplierBillStatus::cases(), 'value'))->toBe(['draft', 'approved', 'posted', 'rejected', 'void'])
        ->and(array_column(PaymentStatus::cases(), 'value'))->toBe(['unpaid', 'partial', 'paid'])
        ->and((new InvalidSupplierBill('Bill error'))->getMessage())->toBe('Bill error');
});

it('executes full supplier bill lifecycle, relations, credits, documents, matches, and immutability', function () use ($createSupplierParty): void {
    $supplier = $createSupplierParty();

    $create = app(CreateSupplierBill::class);
    $update = app(UpdateSupplierBill::class);
    $approve = app(ApproveSupplierBill::class);
    $post = app(PostSupplierBill::class);
    $void = app(VoidSupplierBill::class);
    $addCredit = app(AddSupplierBillCredit::class);
    $attachDoc = app(AttachSupplierBillDocument::class);
    $match = app(MatchSupplierBill::class);
    $duplicateQuery = app(DuplicateSupplierBillQuery::class);

    // Create bill (auto bill_number, lowercase currency, discount + tax)
    $bill = $create->handle([
        'party_id' => $supplier->id,
        'bill_date' => '2026-01-10',
        'due_on' => '2026-02-10',
        'currency' => 'usd',
        'notes' => 'Initial draft',
    ], [
        [
            'account_code' => '5100',
            'description' => 'Raw materials',
            'quantity' => 2,
            'unit_price' => 100,
            'discount_rate' => 10,
            'tax_rate' => 20,
        ],
    ]);

    expect($bill)->toBeInstanceOf(SupplierBill::class)
        ->and($bill->status)->toBe(SupplierBillStatus::Draft)
        ->and($bill->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($bill->currency)->toBe('USD')
        ->and($bill->bill_number)->toStartWith('BILL-')
        ->and((float) $bill->subtotal)->toBe(180.0)
        ->and((float) $bill->tax_total)->toBe(36.0)
        ->and((float) $bill->total)->toBe(216.0)
        ->and($bill->party->is($supplier))->toBeTrue()
        ->and($bill->lines)->toHaveCount(1)
        ->and($bill->lines->first())->toBeInstanceOf(SupplierBillLine::class)
        ->and($bill->lines->first()->bill->is($bill))->toBeTrue()
        ->and((new SupplierBillCreated($bill))->bill->is($bill))->toBeTrue();

    // Update draft bill
    $bill = $update->handle($bill, ['notes' => 'Updated draft'], [
        [
            'account_code' => '5100',
            'description' => 'Updated materials',
            'quantity' => 5,
            'unit_price' => 50,
            'discount_rate' => 0,
            'tax_rate' => 10,
        ],
    ]);

    expect((float) $bill->subtotal)->toBe(250.0)
        ->and((float) $bill->tax_total)->toBe(25.0)
        ->and((float) $bill->total)->toBe(275.0);

    // Duplicate query (with and without exceptId)
    expect($duplicateQuery->handle($supplier->id, $bill->bill_number))->toHaveCount(1)
        ->and($duplicateQuery->handle($supplier->id, $bill->bill_number, $bill->id))->toHaveCount(0);

    // Attach document (and verify idempotency + hidden fields)
    $docHash = hash('sha256', 'bill-pdf');
    $doc = $attachDoc->handle($bill, [
        'path' => 'bills/bill-1.pdf',
        'original_name' => 'bill-1.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => $docHash,
    ]);
    $dupDoc = $attachDoc->handle($bill, [
        'path' => 'bills/bill-1-dup.pdf',
        'original_name' => 'bill-1-dup.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => $docHash,
    ]);

    expect($doc)->toBeInstanceOf(SupplierBillDocument::class)
        ->and($dupDoc->is($doc))->toBeTrue()
        ->and($doc->bill->is($bill))->toBeTrue()
        ->and($doc->toArray())->not->toHaveKeys(['path', 'sha256']);

    // Approve bill
    $bill = $approve->handle($bill, 99);
    expect($bill->status)->toBe(SupplierBillStatus::Approved)
        ->and($bill->approval_status)->toBe('approved')
        ->and($bill->approved_by)->toBe(99)
        ->and($bill->approved_at)->not->toBeNull()
        ->and((new SupplierBillApproved($bill))->bill->is($bill))->toBeTrue();

    // Add credit and match on approved bill
    $bill = $addCredit->handle($bill, [
        'amount' => 25.00,
        'currency' => 'usd',
        'reason' => 'Damaged item allowance',
        'reference' => 'CR-1',
    ]);
    expect($bill->credits)->toHaveCount(1)
        ->and($bill->credits->first())->toBeInstanceOf(SupplierBillCredit::class)
        ->and($bill->credits->first()->bill->is($bill))->toBeTrue()
        ->and($bill->outstanding())->toBe(250.0);

    $bill = $match->handle($bill, [
        'match_type' => 'three_way',
        'matched_type' => 'purchase_order',
        'matched_id' => 'PO-42',
        'quantity' => 5,
        'amount' => 250.00,
    ]);
    expect($bill->matches)->toHaveCount(1)
        ->and($bill->matches->first())->toBeInstanceOf(SupplierBillMatch::class)
        ->and($bill->matches->first()->bill->is($bill))->toBeTrue();

    // Post bill (creates PayableOpenItem)
    $bill = $post->handle($bill);
    expect($bill->status)->toBe(SupplierBillStatus::Posted)
        ->and((new SupplierBillPosted($bill))->bill->is($bill))->toBeTrue()
        ->and(PayableOpenItem::query()->where('source_type', SupplierBill::class)->where('source_id', (string) $bill->id)->exists())->toBeTrue();

    // Posting an already-posted bill with existing open item is idempotent
    $reposted = $post->handle($bill);
    expect($reposted->is($bill))->toBeTrue();

    // Posting an already-posted bill whose open item was missing recreates the open item
    PayableOpenItem::query()->where('source_type', SupplierBill::class)->where('source_id', (string) $bill->id)->delete();
    $repostedAgain = $post->handle($bill);
    expect($repostedAgain->is($bill))->toBeTrue()
        ->and(PayableOpenItem::query()->where('source_type', SupplierBill::class)->where('source_id', (string) $bill->id)->exists())->toBeTrue();

    // Overdue checks
    expect($bill->isOverdue(Carbon::parse('2026-02-05')))->toBeFalse()
        ->and($bill->isOverdue(Carbon::parse('2026-02-15')))->toBeTrue();

    // Immutability on Posted bill: allowed fields work, disallowed fields throw LogicException
    $bill->update(['metadata' => ['checked' => true]]);
    expect($bill->refresh()->metadata)->toBe(['checked' => true]);

    expect(fn () => $bill->update(['notes' => 'Tampered']))
        ->toThrow(LogicException::class, 'Posted and void supplier bills are immutable.');

    // Void posted unpaid bill
    $voided = $void->handle($bill, 'Entered in error');
    expect($voided->status)->toBe(SupplierBillStatus::Void)
        ->and($voided->metadata['void_reason'])->toBe('Entered in error');

    // Immutability on Void bill: even metadata cannot be changed
    expect(fn () => $voided->update(['metadata' => ['changed' => true]]))
        ->toThrow(LogicException::class, 'Posted and void supplier bills are immutable.');
});

it('computes aging buckets across all overdue intervals and rejects bills', function () use ($createSupplierParty): void {
    $supplierA = $createSupplierParty('Supplier A');
    $supplierB = $createSupplierParty('Supplier B');
    $create = app(CreateSupplierBill::class);
    $approve = app(ApproveSupplierBill::class);
    $reject = app(RejectSupplierBill::class);
    $aging = app(SupplierBillAgingQuery::class);

    $asOf = Carbon::parse('2026-06-01');
    $makeApprovedBill = function (Party $party, string $number, ?string $dueOn, float $price) use ($create, $approve): SupplierBill {
        $bill = $create->handle([
            'party_id' => $party->id,
            'bill_number' => $number,
            'bill_date' => '2026-01-01',
            'due_on' => $dueOn,
            'currency' => 'USD',
        ], [
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => $price],
        ]);

        return $approve->handle($bill, 1);
    };

    // current (null due_on and future due_on)
    $noDueBill = $makeApprovedBill($supplierA, 'AG-0', null, 100);
    expect($noDueBill->isOverdue($asOf))->toBeFalse();
    $makeApprovedBill($supplierA, 'AG-CUR', '2026-06-10', 50);
    // 1_30 (15 days overdue)
    $makeApprovedBill($supplierA, 'AG-15', '2026-05-17', 200);
    // 31_60 (45 days overdue)
    $makeApprovedBill($supplierA, 'AG-45', '2026-04-17', 300);
    // 61_90 (75 days overdue)
    $makeApprovedBill($supplierA, 'AG-75', '2026-03-18', 400);
    // over_90 (120 days overdue)
    $makeApprovedBill($supplierB, 'AG-120', '2026-02-01', 500);

    expect($aging->handle(null, $asOf))->toBe([
        'current' => 150.0,
        '1_30' => 200.0,
        '31_60' => 300.0,
        '61_90' => 400.0,
        'over_90' => 500.0,
    ]);

    expect($aging->handle($supplierA->id, $asOf))->toBe([
        'current' => 150.0,
        '1_30' => 200.0,
        '31_60' => 300.0,
        '61_90' => 400.0,
        'over_90' => 0.0,
    ]);

    // Default $asOf = null path
    expect($aging->handle($supplierB->id))->toBeArray();

    // Reject a draft/approved bill
    $rejected = $reject->handle($noDueBill, 'Duplicate invoice');
    expect($rejected->status)->toBe(SupplierBillStatus::Rejected)
        ->and($rejected->approval_status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('Duplicate invoice');
});

it('enforces validation and state guards across all supplier bill actions', function () use ($createSupplierParty): void {
    $supplier = $createSupplierParty('Valid Supplier', PartyType::Supplier);
    $customer = $createSupplierParty('Not A Supplier', PartyType::Customer);

    $create = app(CreateSupplierBill::class);
    $update = app(UpdateSupplierBill::class);
    $approve = app(ApproveSupplierBill::class);
    $reject = app(RejectSupplierBill::class);
    $post = app(PostSupplierBill::class);
    $void = app(VoidSupplierBill::class);
    $addCredit = app(AddSupplierBillCredit::class);
    $match = app(MatchSupplierBill::class);

    $validAttrs = [
        'party_id' => $supplier->id,
        'bill_number' => 'VAL-001',
        'bill_date' => '2026-03-01',
        'due_on' => '2026-03-31',
        'currency' => 'USD',
    ];
    $validLines = [
        ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100],
    ];

    // CreateSupplierBill validation
    expect(fn () => $create->handle($validAttrs, []))
        ->toThrow(InvalidSupplierBill::class, 'A supplier bill requires a supplier, bill date, currency, and at least one line.')
        ->and(fn () => $create->handle([...$validAttrs, 'party_id' => $customer->id], $validLines))
        ->toThrow(InvalidSupplierBill::class, 'Supplier bills can only be created for an existing supplier.')
        ->and(fn () => $create->handle([...$validAttrs, 'due_on' => '2026-02-01'], $validLines))
        ->toThrow(InvalidSupplierBill::class, 'A supplier bill due date cannot precede its bill date.')
        ->and(fn () => $create->handle([...$validAttrs, 'currency' => 'US'], $validLines))
        ->toThrow(InvalidSupplierBill::class, 'A supplier bill currency must be a three-letter code.')
        ->and(fn () => $create->handle($validAttrs, [['description' => '', 'quantity' => 1, 'unit_price' => 10]]))
        ->toThrow(InvalidSupplierBill::class, 'Bill lines have invalid descriptions, quantities, prices, discounts, or tax rates.');

    $draft = $create->handle($validAttrs, $validLines);

    // Duplicate bill number
    expect(fn () => $create->handle($validAttrs, $validLines))
        ->toThrow(InvalidSupplierBill::class, 'A bill with this supplier bill number already exists.');

    // UpdateSupplierBill validation on draft
    expect(fn () => $update->handle($draft, [], []))
        ->toThrow(InvalidSupplierBill::class, 'An updated supplier bill requires at least one line.')
        ->and(fn () => $update->handle($draft, [], [['description' => 'Bad', 'quantity' => 0, 'unit_price' => 10]]))
        ->toThrow(InvalidSupplierBill::class, 'Bill lines have invalid values.');

    // Guards on draft bill: cannot post, void, credit, match, or reject without reason
    expect(fn () => $post->handle($draft))
        ->toThrow(InvalidSupplierBill::class, 'Only approved supplier bills may be posted.')
        ->and(fn () => $void->handle($draft, 'Reason'))
        ->toThrow(InvalidSupplierBill::class, 'Only unpaid posted bills may be voided and a reason is required.')
        ->and(fn () => $addCredit->handle($draft, ['amount' => 10, 'reason' => 'Credit']))
        ->toThrow(InvalidSupplierBill::class, 'A credit requires an approved or posted bill, a reason, and an amount within the outstanding balance.')
        ->and(fn () => $match->handle($draft, ['match_type' => 'two_way', 'matched_type' => 'po', 'matched_id' => '1']))
        ->toThrow(InvalidSupplierBill::class, 'Matching requires an approved or posted bill and a complete target reference.')
        ->and(fn () => $reject->handle($draft, ''))
        ->toThrow(InvalidSupplierBill::class, 'Only draft or approved bills may be rejected and a reason is required.');

    // Zero-total draft bill cannot be approved
    $zeroBill = $create->handle([...$validAttrs, 'bill_number' => 'VAL-ZERO'], [
        ['description' => 'Free item', 'quantity' => 1, 'unit_price' => 0],
    ]);
    expect(fn () => $approve->handle($zeroBill))
        ->toThrow(InvalidSupplierBill::class, 'Only non-empty draft supplier bills may be approved.');

    // Approve & test remaining guards
    $approved = $approve->handle($draft);
    expect(fn () => $update->handle($approved, [], $validLines))
        ->toThrow(InvalidSupplierBill::class, 'Only draft supplier bills may be changed.')
        ->and(fn () => $approve->handle($approved))
        ->toThrow(InvalidSupplierBill::class, 'Only non-empty draft supplier bills may be approved.')
        ->and(fn () => $addCredit->handle($approved, ['amount' => 500, 'reason' => 'Too large']))
        ->toThrow(InvalidSupplierBill::class, 'A credit requires an approved or posted bill, a reason, and an amount within the outstanding balance.')
        ->and(fn () => $match->handle($approved, ['match_type' => '', 'matched_type' => 'po', 'matched_id' => '1']))
        ->toThrow(InvalidSupplierBill::class, 'Matching requires an approved or posted bill and a complete target reference.');

    // Posted bill cannot be rejected, and cannot be voided with blank reason or non-zero amount_paid
    $posted = $post->handle($approved);
    expect(fn () => $reject->handle($posted, 'Too late'))
        ->toThrow(InvalidSupplierBill::class, 'Only draft or approved bills may be rejected and a reason is required.')
        ->and(fn () => $void->handle($posted, ''))
        ->toThrow(InvalidSupplierBill::class, 'Only unpaid posted bills may be voided and a reason is required.');

    $posted->update(['amount_paid' => 50, 'payment_status' => PaymentStatus::Partial]);
    expect(fn () => $void->handle($posted, 'Paid already'))
        ->toThrow(InvalidSupplierBill::class, 'Only unpaid posted bills may be voided and a reason is required.');
});

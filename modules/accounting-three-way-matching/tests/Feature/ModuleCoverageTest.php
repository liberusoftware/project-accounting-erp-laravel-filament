<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ThreeWayMatching\Actions\ApproveMatch;
use Liberu\Accounting\ThreeWayMatching\Actions\CaptureMatchEvidence;
use Liberu\Accounting\ThreeWayMatching\Actions\EvaluateMatch;
use Liberu\Accounting\ThreeWayMatching\Actions\RejectMatch;
use Liberu\Accounting\ThreeWayMatching\Actions\ResolveMatchException;
use Liberu\Accounting\ThreeWayMatching\Enums\ExceptionSeverity;
use Liberu\Accounting\ThreeWayMatching\Enums\ExceptionStatus;
use Liberu\Accounting\ThreeWayMatching\Enums\MatchStatus;
use Liberu\Accounting\ThreeWayMatching\Events\MatchApproved;
use Liberu\Accounting\ThreeWayMatching\Events\MatchEvaluated;
use Liberu\Accounting\ThreeWayMatching\Events\MatchExceptionResolved;
use Liberu\Accounting\ThreeWayMatching\Exceptions\InvalidMatch;
use Liberu\Accounting\ThreeWayMatching\Models\MatchEvidence;
use Liberu\Accounting\ThreeWayMatching\Models\MatchException;
use Liberu\Accounting\ThreeWayMatching\Models\MatchRecord;
use Liberu\Accounting\ThreeWayMatching\Queries\ExceptionQuery;
use Liberu\Accounting\ThreeWayMatching\Queries\MatchQuery;
use Liberu\Accounting\ThreeWayMatching\ThreeWayMatchingServiceProvider;

function baseMatchPayload(array $overrides = []): array
{
    return array_merge([
        'purchase_order_type' => 'PurchaseOrder',
        'purchase_order_id' => 'PO-1',
        'receipt_type' => 'GoodsReceipt',
        'receipt_id' => 'GR-1',
        'bill_type' => 'VendorBill',
        'bill_id' => 'BILL-1',
        'currency' => 'USD',
        'ordered_quantity' => 10,
        'received_quantity' => 10,
        'billed_quantity' => 10,
        'ordered_unit_price' => 25,
        'billed_unit_price' => 25,
        'expected_tax' => 10,
        'billed_tax' => 10,
    ], $overrides);
}

it('registers service provider and creates matching tables', function (): void {
    expect(app()->getProvider(ThreeWayMatchingServiceProvider::class))
        ->toBeInstanceOf(ThreeWayMatchingServiceProvider::class);
    expect(Schema::hasTable('accounting_three_way_matches'))->toBeTrue();
    expect(Schema::hasTable('accounting_three_way_match_exceptions'))->toBeTrue();
    expect(Schema::hasTable('accounting_three_way_match_evidence'))->toBeTrue();
});

it('exposes enum cases and instantiates match events', function (): void {
    expect(array_map(static fn (MatchStatus $s): string => $s->value, MatchStatus::cases()))
        ->toBe(['matched', 'partial', 'exception', 'approved', 'rejected']);
    expect(array_map(static fn (ExceptionSeverity $s): string => $s->value, ExceptionSeverity::cases()))
        ->toBe(['warning', 'blocking']);
    expect(array_map(static fn (ExceptionStatus $s): string => $s->value, ExceptionStatus::cases()))
        ->toBe(['open', 'resolved', 'waived']);

    $match = app(EvaluateMatch::class)->handle(baseMatchPayload());
    $exception = $match->exceptions()->create([
        'kind' => 'manual',
        'severity' => ExceptionSeverity::Warning,
        'status' => ExceptionStatus::Open,
    ]);

    $evaluatedEvent = new MatchEvaluated($match);
    $approvedEvent = new MatchApproved($match);
    $resolvedEvent = new MatchExceptionResolved($exception);

    expect($evaluatedEvent->match->is($match))->toBeTrue()
        ->and($approvedEvent->match->is($match))->toBeTrue()
        ->and($resolvedEvent->exception->is($exception))->toBeTrue();
});

it('validates match evaluation inputs and handles idempotency', function (): void {
    $evaluate = app(EvaluateMatch::class);

    foreach (['purchase_order_type', 'purchase_order_id', 'receipt_type', 'receipt_id', 'bill_type', 'bill_id', 'currency'] as $requiredKey) {
        $payload = baseMatchPayload([$requiredKey => '']);
        expect(fn () => $evaluate->handle($payload))
            ->toThrow(InvalidMatch::class, "Missing matching reference [{$requiredKey}].");
    }

    foreach (['ordered_quantity', 'received_quantity', 'billed_quantity', 'ordered_unit_price', 'billed_unit_price', 'expected_tax', 'billed_tax'] as $numericKey) {
        $missing = baseMatchPayload();
        unset($missing[$numericKey]);
        expect(fn () => $evaluate->handle($missing))
            ->toThrow(InvalidMatch::class, "Matching value [{$numericKey}] must be zero or greater.");

        $negative = baseMatchPayload([$numericKey => -1]);
        expect(fn () => $evaluate->handle($negative))
            ->toThrow(InvalidMatch::class, "Matching value [{$numericKey}] must be zero or greater.");
    }

    foreach (['ordered_quantity', 'received_quantity', 'billed_quantity'] as $qtyKey) {
        expect(fn () => $evaluate->handle(baseMatchPayload([$qtyKey => 0])))
            ->toThrow(InvalidMatch::class, 'Order, receipt, and bill quantities must be positive.');
    }

    $first = $evaluate->handle(baseMatchPayload(['ordered_unit_price' => 0, 'billed_unit_price' => 0, 'expected_tax' => 0, 'billed_tax' => 0]));
    expect($first->status)->toBe(MatchStatus::Matched)
        ->and($first->exceptions)->toHaveCount(0);

    $duplicate = $evaluate->handle(baseMatchPayload(['ordered_quantity' => 999]));
    expect($duplicate->id)->toBe($first->id)
        ->and((float) $duplicate->ordered_quantity)->toBe(10.0);
});

it('evaluates partial matches and all variance exception branches', function (): void {
    $evaluate = app(EvaluateMatch::class);

    $partial = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-PARTIAL',
        'billed_quantity' => 7,
    ]));

    expect($partial->status)->toBe(MatchStatus::Partial)
        ->and($partial->metadata['base_status'])->toBe(MatchStatus::Partial->value)
        ->and($partial->hasBlockingExceptions())->toBeFalse()
        ->and($partial->exceptions)->toHaveCount(1)
        ->and($partial->exceptions->first()->kind)->toBe('partial_quantity')
        ->and($partial->exceptions->first()->severity)->toBe(ExceptionSeverity::Warning);

    $withinTolerance = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-TOL',
        'received_quantity' => 11,
        'billed_quantity' => 11,
        'billed_unit_price' => 26,
        'billed_tax' => 11,
        'quantity_tolerance' => 1,
        'price_tolerance' => 1,
        'tax_tolerance' => 1,
    ]));

    expect($withinTolerance->status)->toBe(MatchStatus::Matched)
        ->and($withinTolerance->exceptions)->toHaveCount(0);

    $withExceptions = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-EXC',
        'ordered_quantity' => 10,
        'received_quantity' => 12,
        'billed_quantity' => 15,
        'ordered_unit_price' => 20,
        'billed_unit_price' => 25,
        'expected_tax' => 5,
        'billed_tax' => 12,
        'quantity_tolerance' => -5,
    ]));

    expect($withExceptions->status)->toBe(MatchStatus::Exception)
        ->and($withExceptions->hasBlockingExceptions())->toBeTrue()
        ->and((float) $withExceptions->quantity_tolerance)->toBe(0.0)
        ->and($withExceptions->exceptions->pluck('kind')->all())->toBe([
            'received_exceeds_ordered',
            'billed_exceeds_received',
            'price_variance',
            'tax_variance',
        ]);
});

it('resolves and waives exceptions and restores match base status', function (): void {
    $evaluate = app(EvaluateMatch::class);
    $resolve = app(ResolveMatchException::class);

    $partialWithPriceVariance = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-PARTIAL-EXC',
        'ordered_quantity' => 10,
        'received_quantity' => 10,
        'billed_quantity' => 6,
        'ordered_unit_price' => 10,
        'billed_unit_price' => 15,
    ]));

    expect($partialWithPriceVariance->status)->toBe(MatchStatus::Exception);

    $blocking = $partialWithPriceVariance->exceptions->firstWhere('severity', ExceptionSeverity::Blocking);
    $warning = $partialWithPriceVariance->exceptions->firstWhere('severity', ExceptionSeverity::Warning);

    expect($blocking)->toBeInstanceOf(MatchException::class)
        ->and($blocking->match->is($partialWithPriceVariance))->toBeTrue();

    expect(fn () => $resolve->handle($blocking, 42, ''))
        ->toThrow(InvalidMatch::class, 'An open exception requires a resolution.');

    $resolved = $resolve->handle($blocking, 42, 'Vendor price adjustment accepted');
    expect($resolved->status)->toBe(ExceptionStatus::Resolved)
        ->and($resolved->resolution)->toBe('Vendor price adjustment accepted')
        ->and($resolved->resolved_by)->toBe(42)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and($partialWithPriceVariance->refresh()->status)->toBe(MatchStatus::Partial);

    expect(fn () => $resolve->handle($resolved, 42, 'Retry'))
        ->toThrow(InvalidMatch::class, 'An open exception requires a resolution.');

    $waived = $resolve->handle($warning, 42, 'Short delivery accepted', waive: true);
    expect($waived->status)->toBe(ExceptionStatus::Waived);
});

it('approves and rejects matches and enforces blocking exception and status guards', function (): void {
    $evaluate = app(EvaluateMatch::class);
    $approve = app(ApproveMatch::class);
    $reject = app(RejectMatch::class);

    $matched = $evaluate->handle(baseMatchPayload(['bill_id' => 'BILL-APPROVE']));
    $approved = $approve->handle($matched, 7);

    expect($approved->status)->toBe(MatchStatus::Approved)
        ->and($approved->approved_by)->toBe(7)
        ->and($approved->approved_at)->not->toBeNull();

    expect(fn () => $approve->handle($approved, 7))
        ->toThrow(InvalidMatch::class, 'This match cannot be approved without resolving blocking exceptions or recording an override reason.');

    expect(fn () => $reject->handle($approved, 'Too late'))
        ->toThrow(InvalidMatch::class, 'Approved matches cannot be rejected and a reason is required.');

    $withException = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-OVERRIDE',
        'billed_unit_price' => 30,
    ]));

    expect(fn () => $approve->handle($withException, 8))
        ->toThrow(InvalidMatch::class);

    $overridden = $approve->handle($withException, 8, 'CFO approved price variance');
    expect($overridden->status)->toBe(MatchStatus::Approved)
        ->and($overridden->metadata['approval_override_reason'])->toBe('CFO approved price variance');

    $toReject = $evaluate->handle(baseMatchPayload(['bill_id' => 'BILL-REJECT']));
    expect(fn () => $reject->handle($toReject, ''))
        ->toThrow(InvalidMatch::class, 'Approved matches cannot be rejected and a reason is required.');

    $rejected = $reject->handle($toReject, 'Duplicate bill');
    expect($rejected->status)->toBe(MatchStatus::Rejected)
        ->and($rejected->rejected_reason)->toBe('Duplicate bill');

    expect(fn () => $approve->handle($rejected, 9))
        ->toThrow(InvalidMatch::class);
});

it('captures idempotent match evidence and queries matches and open exceptions', function (): void {
    $evaluate = app(EvaluateMatch::class);
    $capture = app(CaptureMatchEvidence::class);

    $matched = $evaluate->handle(baseMatchPayload(['bill_id' => 'BILL-Q1']));
    $withException = $evaluate->handle(baseMatchPayload([
        'bill_id' => 'BILL-Q2',
        'billed_tax' => 25,
    ]));
    $rejected = app(RejectMatch::class)->handle(
        $evaluate->handle(baseMatchPayload(['bill_id' => 'BILL-Q3'])),
        'Cancelled'
    );

    $evidence1 = $capture->handle($matched, 'VendorBill', 'BILL-Q1', ['total' => 260], 11);
    $evidence2 = $capture->handle($matched, 'VendorBill', 'BILL-Q1', ['total' => 260], 11);
    $evidence3 = $capture->handle($matched, 'VendorBill', 'BILL-Q1', ['total' => 270], 11);

    expect($evidence1)->toBeInstanceOf(MatchEvidence::class)
        ->and($evidence1->id)->toBe($evidence2->id)
        ->and($evidence3->id)->not->toBe($evidence1->id)
        ->and($evidence1->match->is($matched))->toBeTrue()
        ->and($evidence1->snapshot)->toBe(['total' => 260])
        ->and($evidence1->toArray())->not->toHaveKeys(['snapshot_hash', 'snapshot']);

    expect(MatchRecord::query()->pending()->pluck('id')->all())
        ->toContain($matched->id, $withException->id)
        ->not->toContain($rejected->id);

    $matchQuery = app(MatchQuery::class);
    $allMatches = $matchQuery->paginate(perPage: 200);
    expect($allMatches->total())->toBe(3)
        ->and($allMatches->perPage())->toBe(100);

    $filteredMatches = $matchQuery->paginate(status: MatchStatus::Exception->value, perPage: 0);
    expect($filteredMatches->total())->toBe(1)
        ->and($filteredMatches->perPage())->toBe(1)
        ->and($filteredMatches->items()[0]->id)->toBe($withException->id);

    $exceptionQuery = app(ExceptionQuery::class);
    expect($exceptionQuery->open())->toHaveCount(1)
        ->and($exceptionQuery->open($matched->id))->toHaveCount(0)
        ->and($exceptionQuery->open($withException->id))->toHaveCount(1);
});

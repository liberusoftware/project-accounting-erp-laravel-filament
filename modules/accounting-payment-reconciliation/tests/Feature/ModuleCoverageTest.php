<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\PaymentReconciliation\Actions\IdentifyMissingItems;
use Liberu\Accounting\PaymentReconciliation\Actions\ImportSettlement;
use Liberu\Accounting\PaymentReconciliation\Actions\MatchSettlementItem;
use Liberu\Accounting\PaymentReconciliation\Actions\RecordProviderDrift;
use Liberu\Accounting\PaymentReconciliation\Actions\ResolveReconciliationException;
use Liberu\Accounting\PaymentReconciliation\Enums\DriftStatus;
use Liberu\Accounting\PaymentReconciliation\Enums\ReconciliationExceptionStatus;
use Liberu\Accounting\PaymentReconciliation\Enums\SettlementItemStatus;
use Liberu\Accounting\PaymentReconciliation\Enums\SettlementItemType;
use Liberu\Accounting\PaymentReconciliation\Enums\SettlementStatus;
use Liberu\Accounting\PaymentReconciliation\Events\ReconciliationExceptionResolved;
use Liberu\Accounting\PaymentReconciliation\Events\SettlementImported;
use Liberu\Accounting\PaymentReconciliation\Events\SettlementMatched;
use Liberu\Accounting\PaymentReconciliation\Exceptions\InvalidReconciliation;
use Liberu\Accounting\PaymentReconciliation\Models\AuditEvidence;
use Liberu\Accounting\PaymentReconciliation\Models\ProviderDrift;
use Liberu\Accounting\PaymentReconciliation\Models\ReconciliationException;
use Liberu\Accounting\PaymentReconciliation\Models\SettlementItem;
use Liberu\Accounting\PaymentReconciliation\Models\SettlementMatch;
use Liberu\Accounting\PaymentReconciliation\Models\SettlementRun;
use Liberu\Accounting\PaymentReconciliation\PaymentReconciliationServiceProvider;
use Liberu\Accounting\PaymentReconciliation\Queries\SettlementQuery;

it('registers and boots the payment reconciliation service provider', function (): void {
    $provider = $this->app->register(PaymentReconciliationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(PaymentReconciliationServiceProvider::class);
});

it('exposes all payment reconciliation enum cases', function (): void {
    expect(array_map(fn (DriftStatus $s): string => $s->value, DriftStatus::cases()))
        ->toBe(['open', 'resolved', 'accepted']);
    expect(array_map(fn (ReconciliationExceptionStatus $s): string => $s->value, ReconciliationExceptionStatus::cases()))
        ->toBe(['open', 'resolved', 'waived']);
    expect(array_map(fn (SettlementItemStatus $s): string => $s->value, SettlementItemStatus::cases()))
        ->toBe(['unmatched', 'partially_matched', 'matched', 'exception']);
    expect(array_map(fn (SettlementItemType $t): string => $t->value, SettlementItemType::cases()))
        ->toBe(['charge', 'fee', 'refund', 'dispute', 'adjustment']);
    expect(array_map(fn (SettlementStatus $s): string => $s->value, SettlementStatus::cases()))
        ->toBe(['imported', 'partially_matched', 'matched', 'exception', 'reconciled', 'failed']);
});

it('validates and imports settlements idempotently and rejects conflicting payloads', function (): void {
    Event::fake([SettlementImported::class]);
    $import = new ImportSettlement();

    $base = [
        'team_id' => 3,
        'provider' => 'stripe',
        'merchant_ref' => 'acct_123',
        'settlement_ref' => 'po_100',
        'currency' => 'gbp',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-07',
        'actor_id' => 9,
    ];
    $items = [
        ['external_ref' => 'ch_1', 'type' => 'charge', 'gross_amount' => 100.00, 'fee_amount' => 3.20, 'net_amount' => 96.80],
        ['external_ref' => 'rf_1', 'type' => 'refund', 'refund_amount' => 10.00, 'dispute_amount' => 5.00, 'net_amount' => 15.00],
    ];

    expect(fn () => $import->handle(array_diff_key($base, ['provider' => true]), $items))
        ->toThrow(InvalidReconciliation::class, 'Settlement field [provider] is required.');

    expect(fn () => $import->handle($base, []))
        ->toThrow(InvalidReconciliation::class, 'A settlement must contain at least one item.');

    expect(fn () => $import->handle(array_merge($base, ['currency' => 'GB']), $items))
        ->toThrow(InvalidReconciliation::class, 'Currency must be an ISO 4217 code.');

    expect(fn () => $import->handle($base, [['external_ref' => '', 'type' => 'charge']]))
        ->toThrow(InvalidReconciliation::class, 'Every settlement item requires a unique external reference and supported type.');

    expect(fn () => $import->handle($base, [['external_ref' => 'ch_1', 'type' => 'charge', 'currency' => 'USD']]))
        ->toThrow(InvalidReconciliation::class, 'Settlement items must use the run currency.');

    expect(fn () => $import->handle($base, [['external_ref' => 'ch_1', 'type' => 'charge', 'gross_amount' => -1]]))
        ->toThrow(InvalidReconciliation::class, 'Item amount [gross_amount] cannot be negative.');

    $run = $import->handle($base, $items);

    expect($run)->toBeInstanceOf(SettlementRun::class)
        ->and($run->currency)->toBe('GBP')
        ->and($run->status)->toBe(SettlementStatus::Imported)
        ->and((float) $run->gross_amount)->toBe(100.0)
        ->and((float) $run->fee_amount)->toBe(3.2)
        ->and((float) $run->refund_amount)->toBe(10.0)
        ->and((float) $run->dispute_amount)->toBe(5.0)
        ->and((float) $run->net_amount)->toBe(111.8)
        ->and($run->items)->toHaveCount(2)
        ->and($run->audits)->toHaveCount(1);

    /** @var SettlementItem $firstItem */
    $firstItem = $run->items->first();
    expect($firstItem->amount())->toBe(96.8)
        ->and($firstItem->run->is($run))->toBeTrue()
        ->and($run->audits->first())->toBeInstanceOf(AuditEvidence::class)
        ->and($run->audits->first()->run->is($run))->toBeTrue();

    Event::assertDispatched(SettlementImported::class, fn (SettlementImported $e): bool => $e->run->is($run));

    // Idempotent re-import
    $same = $import->handle($base, $items);
    expect($same->id)->toBe($run->id);

    // Different payload with same key fails
    expect(fn () => $import->handle($base, [['external_ref' => 'ch_2', 'type' => 'charge', 'net_amount' => 50]]))
        ->toThrow(InvalidReconciliation::class, 'Settlement reference already exists with different source data.');
});

it('matches settlement items partially, completely, and idempotently', function (): void {
    Event::fake([SettlementMatched::class]);
    $run = (new ImportSettlement())->handle([
        'team_id' => 4,
        'provider' => 'adyen',
        'settlement_ref' => 'batch_1',
        'currency' => 'EUR',
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-02',
    ], [
        ['external_ref' => 'tx_1', 'type' => 'charge', 'gross_amount' => 100, 'net_amount' => 100],
        ['external_ref' => 'tx_2', 'type' => 'adjustment', 'gross_amount' => 50, 'net_amount' => 50],
    ]);

    $item1 = $run->items->firstWhere('external_ref', 'tx_1');
    $item2 = $run->items->firstWhere('external_ref', 'tx_2');
    $matcher = new MatchSettlementItem();

    expect(fn () => $matcher->handle($item1, '', 'INV-1', 50))
        ->toThrow(InvalidReconciliation::class, 'A match requires a reference and a positive amount.');

    expect(fn () => $matcher->handle($item1, 'invoice', 'INV-1', 150))
        ->toThrow(InvalidReconciliation::class, 'Matched amount cannot exceed the settlement item net amount.');

    $partial = $matcher->handle($item1, 'invoice', 'INV-1', 40.0, 12, 'idem-1');
    expect($partial)->toBeInstanceOf(SettlementMatch::class)
        ->and($partial->status)->toBe('partial')
        ->and($partial->run->is($run))->toBeTrue()
        ->and($partial->item->is($item1))->toBeTrue()
        ->and($item1->fresh()->status)->toBe(SettlementItemStatus::PartiallyMatched)
        ->and($run->fresh()->status)->toBe(SettlementStatus::PartiallyMatched)
        ->and($item1->matches)->toHaveCount(1);

    // Idempotent duplicate match
    $dup = $matcher->handle($item1, 'invoice', 'INV-1', 40.0, 12, 'idem-1');
    expect($dup->id)->toBe($partial->id);

    // Exceeding cumulative matched amount
    expect(fn () => $matcher->handle($item1, 'invoice', 'INV-2', 70.0))
        ->toThrow(InvalidReconciliation::class, 'Total matched amount cannot exceed the settlement item net amount.');

    // Complete item1 and item2
    $matcher->handle($item1, 'invoice', 'INV-2', 60.0, 12);
    expect($item1->fresh()->status)->toBe(SettlementItemStatus::Matched)
        ->and($run->fresh()->status)->toBe(SettlementStatus::PartiallyMatched);

    $matcher->handle($item2, 'invoice', 'INV-3', 50.0, 12);
    expect($item2->fresh()->status)->toBe(SettlementItemStatus::Matched)
        ->and($run->fresh()->status)->toBe(SettlementStatus::Matched);

    Event::assertDispatched(SettlementMatched::class, 3);
});

it('identifies missing items, records provider drift, and resolves or waives exceptions', function (): void {
    Event::fake([ReconciliationExceptionResolved::class]);
    $run = (new ImportSettlement())->handle([
        'team_id' => 5,
        'provider' => 'paypal',
        'settlement_ref' => 'pp_99',
        'currency' => 'USD',
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-05',
    ], [
        ['external_ref' => 'existing_1', 'type' => 'charge', 'net_amount' => 25],
    ]);

    $missingAction = new IdentifyMissingItems();
    expect(fn () => $missingAction->handle($run, [['external_ref' => '  ']]))
        ->toThrow(InvalidReconciliation::class, 'Expected item reference is required.');

    $created = $missingAction->handle($run, [
        ['external_ref' => 'existing_1', 'expected_amount' => 25],
        ['external_ref' => 'missing_1', 'expected_amount' => 45.50],
    ]);
    expect($created)->toHaveCount(1)
        ->and($created[0])->toBeInstanceOf(ReconciliationException::class)
        ->and($created[0]->run->is($run))->toBeTrue()
        ->and($run->fresh()->status)->toBe(SettlementStatus::Exception);

    // Repeating same missing ref creates no duplicates
    $second = $missingAction->handle($run, [['external_ref' => 'missing_1']]);
    expect($second)->toBe([]);

    // Drift recording
    $driftAction = new RecordProviderDrift();
    expect(fn () => $driftAction->handle($run, '', 'a', 'b'))
        ->toThrow(InvalidReconciliation::class, 'A drift requires a changed provider value and field.');
    expect(fn () => $driftAction->handle($run, 'fee', '10', '10'))
        ->toThrow(InvalidReconciliation::class, 'A drift requires a changed provider value and field.');

    $drift = $driftAction->handle($run, 'fee_schedule', ['tier' => 1], ['tier' => 2], 'critical');
    expect($drift)->toBeInstanceOf(ProviderDrift::class)
        ->and($drift->status)->toBe(DriftStatus::Open)
        ->and($drift->run->is($run))->toBeTrue()
        ->and($run->drifts)->toHaveCount(1);

    // Exception resolution & waiver
    $resolveAction = new ResolveReconciliationException();
    expect(fn () => $resolveAction->handle($created[0], 77, '  '))
        ->toThrow(InvalidReconciliation::class, 'Only open exceptions can be resolved and a resolution is required.');

    $resolved = $resolveAction->handle($created[0], 77, 'Posted manual adjustment');
    expect($resolved->status)->toBe(ReconciliationExceptionStatus::Resolved)
        ->and($resolved->resolved_by)->toBe(77)
        ->and($resolved->resolved_at)->not->toBeNull();

    // Resolving an already-resolved exception throws
    expect(fn () => $resolveAction->handle($resolved, 77, 'Again'))
        ->toThrow(InvalidReconciliation::class);

    $waivable = $missingAction->handle($run, [['external_ref' => 'missing_2', 'expected_amount' => 5.0]]);
    $waived = $resolveAction->handle($waivable[0], 77, 'Below threshold', waive: true);
    expect($waived->status)->toBe(ReconciliationExceptionStatus::Waived);

    Event::assertDispatched(ReconciliationExceptionResolved::class, 2);
});

it('paginates and summarizes settlement runs', function (): void {
    $query = new SettlementQuery();
    expect($query->paginate()->total())->toBe(0)
        ->and($query->summary()['count'])->toBe(0);

    $import = new ImportSettlement();
    $run1 = $import->handle([
        'team_id' => 8,
        'provider' => 'stripe',
        'settlement_ref' => 's1',
        'currency' => 'GBP',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-02',
    ], [
        ['external_ref' => 'i1', 'type' => 'charge', 'gross_amount' => 100, 'fee_amount' => 2, 'net_amount' => 98],
    ]);
    (new MatchSettlementItem())->handle($run1->items->first(), 'invoice', 'INV-9', 98.0);

    $run2 = $import->handle([
        'team_id' => 8,
        'provider' => 'stripe',
        'settlement_ref' => 's2',
        'currency' => 'GBP',
        'period_start' => '2026-06-03',
        'period_end' => '2026-06-04',
    ], [
        ['external_ref' => 'i2', 'type' => 'refund', 'refund_amount' => 20, 'dispute_amount' => 5, 'net_amount' => 25],
    ]);
    (new RecordProviderDrift())->handle($run2, 'net_amount', 25, 20);

    expect($query->paginate(teamId: 8, status: 'matched')->total())->toBe(1)
        ->and($query->paginate(perPage: 200)->perPage())->toBe(100);

    $summary = $query->summary(8);
    expect($summary)->toBe([
        'count' => 2,
        'gross_amount' => 100.0,
        'fees' => 2.0,
        'refunds' => 20.0,
        'disputes' => 5.0,
        'net_amount' => 123.0,
        'matched' => 1,
        'exceptions' => 1,
    ]);
});

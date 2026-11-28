<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Liberu\Accounting\AccountsPayable\AccountsPayableServiceProvider;
use Liberu\Accounting\AccountsPayable\Actions\ApplyPayment;
use Liberu\Accounting\AccountsPayable\Actions\CreateOpenItem;
use Liberu\Accounting\AccountsPayable\Actions\EnsureAccount;
use Liberu\Accounting\AccountsPayable\Actions\OpenDispute;
use Liberu\Accounting\AccountsPayable\Actions\RecalculateAccountBalance;
use Liberu\Accounting\AccountsPayable\Actions\RecordPayment;
use Liberu\Accounting\AccountsPayable\Actions\ResolveDispute;
use Liberu\Accounting\AccountsPayable\Actions\SetPaymentControl;
use Liberu\Accounting\AccountsPayable\Enums\DisputeStatus;
use Liberu\Accounting\AccountsPayable\Enums\PayableStatus;
use Liberu\Accounting\AccountsPayable\Events\DisputeOpened;
use Liberu\Accounting\AccountsPayable\Events\DisputeResolved;
use Liberu\Accounting\AccountsPayable\Events\OpenItemCreated;
use Liberu\Accounting\AccountsPayable\Events\PaymentApplied;
use Liberu\Accounting\AccountsPayable\Events\PaymentControlChanged;
use Liberu\Accounting\AccountsPayable\Events\PaymentRecorded;
use Liberu\Accounting\AccountsPayable\Exceptions\InvalidPayable;
use Liberu\Accounting\AccountsPayable\Models\PayableAccount;
use Liberu\Accounting\AccountsPayable\Models\PayableDispute;
use Liberu\Accounting\AccountsPayable\Models\PaymentApplication;
use Liberu\Accounting\AccountsPayable\Queries\AgingQuery;
use Liberu\Accounting\AccountsPayable\Queries\ControlAccountReconciliationQuery;
use Liberu\Accounting\AccountsPayable\Queries\SupplierSubledgerQuery;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;

function createApSupplier(LegalEntity $entity, string $name = 'Acme Supplier'): Party
{
    return Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Supplier,
        'name' => $name,
    ]);
}

it('boots the service provider and covers enums, exception, events, models, actions, and queries', function (): void {
    $provider = $this->app->register(AccountsPayableServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountsPayableServiceProvider::class);

    expect(array_map(fn (DisputeStatus $s): string => $s->value, DisputeStatus::cases()))
        ->toBe(['open', 'resolved', 'rejected']);
    expect(array_map(fn (PayableStatus $s): string => $s->value, PayableStatus::cases()))
        ->toBe(['open', 'partial', 'settled', 'unapplied', 'applied', 'disputed']);

    $exception = new InvalidPayable('Bad payable');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad payable');

    $entity = LegalEntity::create([
        'name' => 'AP Test Entity',
        'currency_code' => 'USD',
    ]);
    $supplier = createApSupplier($entity, 'Vendor One');
    $supplier2 = createApSupplier($entity, 'Vendor Two');
    $customer = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'name' => 'Not A Supplier',
    ]);

    $createOpenItem = app(CreateOpenItem::class);
    $recordPayment = app(RecordPayment::class);
    $applyPayment = app(ApplyPayment::class);
    $openDispute = app(OpenDispute::class);
    $resolveDispute = app(ResolveDispute::class);
    $setPaymentControl = app(SetPaymentControl::class);
    $ensureAccount = app(EnsureAccount::class);
    $recalcBalance = app(RecalculateAccountBalance::class);

    // Empty queries
    expect(app(AgingQuery::class)->handle())->toBe([
        'current' => 0.0,
        '1_30' => 0.0,
        '31_60' => 0.0,
        '61_90' => 0.0,
        'over_90' => 0.0,
    ]);
    expect(app(ControlAccountReconciliationQuery::class)->handle())->toMatchArray([
        'control_account_code' => 'accounts_payable',
        'subledger_balance' => 0.0,
        'control_balance' => null,
        'difference' => null,
    ]);
    expect(app(SupplierSubledgerQuery::class)->handle($supplier->id)['balance'])->toBe(0.0);

    // CreateOpenItem validation branches
    expect(fn () => $createOpenItem->handle([
        'party_id' => null,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class, 'A payable requires a supplier, reference, positive amount, and currency.');

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => '',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 0,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => '',
    ]))->toThrow(InvalidPayable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $customer->id,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class, 'Open items can only be created for an existing supplier.');

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-10',
        'due_on' => '2026-01-05',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class, 'A payable due date cannot precede its issue date.');

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => 'BILL-1',
        'issued_on' => '2026-01-10',
        'original_amount' => 100,
        'currency' => 'US',
    ]))->toThrow(InvalidPayable::class, 'A payable currency must be a three-letter code.');

    // CreateOpenItem happy path + lowercase currency normalization
    $item1 = $createOpenItem->handle([
        'party_id' => $supplier->id,
        'source_type' => 'VendorBill',
        'source_id' => '101',
        'reference' => 'BILL-101',
        'issued_on' => '2026-01-01',
        'due_on' => '2026-01-15',
        'original_amount' => 250.00,
        'currency' => 'usd',
        'payment_terms' => 'NET15',
        'metadata' => ['dept' => 'ops'],
    ]);

    expect($item1->currency)->toBe('USD')
        ->and($item1->status)->toBe(PayableStatus::Open)
        ->and((float) $item1->paid_amount)->toBe(0.0)
        ->and($item1->outstanding())->toBe(250.0)
        ->and($item1->party->id)->toBe($supplier->id);

    // Duplicate source check
    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'source_type' => 'VendorBill',
        'source_id' => '101',
        'reference' => 'BILL-101-DUP',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidPayable::class, 'A payable for this source already exists.');

    $account = $ensureAccount->handle($supplier->id);
    expect((float) $account->current_balance)->toBe(250.0)
        ->and($account->party->id)->toBe($supplier->id)
        ->and($account->openItems)->toHaveCount(1);

    // RecordPayment validation branches
    expect(fn () => $recordPayment->handle(['amount' => 0, 'currency' => 'USD']))
        ->toThrow(InvalidPayable::class, 'A payment requires a positive amount and currency.');
    expect(fn () => $recordPayment->handle(['amount' => 50, 'currency' => 'US']))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $recordPayment->handle(['party_id' => $customer->id, 'amount' => 50, 'currency' => 'USD']))
        ->toThrow(InvalidPayable::class, 'Payments can only be assigned to an existing supplier.');

    // Record unassigned payment & assigned supplier payment
    $unassignedPayment = $recordPayment->handle([
        'amount' => 100.00,
        'currency' => 'usd',
        'reference' => 'PAY-UNASSIGNED',
    ]);
    expect($unassignedPayment->party_id)->toBeNull()
        ->and($unassignedPayment->currency)->toBe('USD')
        ->and($unassignedPayment->status)->toBe(PayableStatus::Unapplied)
        ->and($unassignedPayment->unapplied())->toBe(100.0);

    $supplierPayment = $recordPayment->handle([
        'party_id' => $supplier->id,
        'paid_on' => '2026-01-10',
        'amount' => 200.00,
        'currency' => 'USD',
        'reference' => 'PAY-1',
    ]);
    expect($supplierPayment->party->id)->toBe($supplier->id);

    $otherCurrencyPayment = $recordPayment->handle([
        'party_id' => $supplier->id,
        'amount' => 50.00,
        'currency' => 'EUR',
    ]);
    $otherSupplierPayment = $recordPayment->handle([
        'party_id' => $supplier2->id,
        'amount' => 50.00,
        'currency' => 'USD',
    ]);

    // ApplyPayment validation branches
    expect(fn () => $applyPayment->handle($supplierPayment, $item1, 0))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $applyPayment->handle($otherSupplierPayment, $item1, 10))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $applyPayment->handle($otherCurrencyPayment, $item1, 10))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $applyPayment->handle($supplierPayment, $item1, 300))
        ->toThrow(InvalidPayable::class);

    // Payment hold blocks application
    expect(fn () => $setPaymentControl->handle($customer->id, true, 'Invalid'))
        ->toThrow(InvalidPayable::class, 'Payment control can only be configured for an existing supplier.');

    $account = $setPaymentControl->handle($supplier->id, true, 'Compliance review');
    expect($account->payment_hold)->toBeTrue()
        ->and($account->hold_reason)->toBe('Compliance review');

    expect(fn () => $applyPayment->handle($supplierPayment, $item1, 100))
        ->toThrow(InvalidPayable::class);

    // Release hold
    $account = $setPaymentControl->handle($supplier->id, false, null);
    expect($account->payment_hold)->toBeFalse()
        ->and($account->hold_reason)->toBe('Compliance review');

    // Partial payment application (payment partially applied, item partially settled)
    $applied = $applyPayment->handle($supplierPayment, $item1, 150.00);
    expect($applied->status)->toBe(PayableStatus::Partial)
        ->and($applied->unapplied())->toBe(50.0)
        ->and($applied->applications)->toHaveCount(1);

    $application = $applied->applications->first();
    expect($application)->toBeInstanceOf(PaymentApplication::class)
        ->and($application->payment->id)->toBe($supplierPayment->id)
        ->and($application->openItem->id)->toBe($item1->id);

    $item1->refresh();
    expect($item1->status)->toBe(PayableStatus::Partial)
        ->and($item1->outstanding())->toBe(100.0)
        ->and((float) $account->fresh()->current_balance)->toBe(100.0);

    // Duplicate application of same payment to same item blocked
    expect(fn () => $applyPayment->handle($supplierPayment, $item1, 50.00))
        ->toThrow(InvalidPayable::class, 'A payment can only be applied to the same open item once.');

    // Applying > item->outstanding() blocked
    $bigPayment = $recordPayment->handle([
        'party_id' => $supplier->id,
        'amount' => 500.00,
        'currency' => 'USD',
    ]);
    expect(fn () => $applyPayment->handle($bigPayment, $item1, 150.00))
        ->toThrow(InvalidPayable::class);

    // Dispute flow on item1 (outstanding 100)
    expect(fn () => $openDispute->handle($item1, '', 50))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $openDispute->handle($item1, 'Wrong qty', 0))
        ->toThrow(InvalidPayable::class);
    expect(fn () => $openDispute->handle($item1, 'Wrong qty', 150))
        ->toThrow(InvalidPayable::class);

    $dispute1 = $openDispute->handle($item1, 'Damaged goods');
    expect($dispute1)->toBeInstanceOf(PayableDispute::class)
        ->and((float) $dispute1->amount)->toBe(100.0)
        ->and($dispute1->status)->toBe(DisputeStatus::Open)
        ->and($dispute1->openItem->id)->toBe($item1->id)
        ->and($item1->fresh()->status)->toBe(PayableStatus::Disputed)
        ->and($item1->disputes)->toHaveCount(1);

    // Cannot open a second open dispute
    expect(fn () => $openDispute->handle($item1->fresh(), 'Another reason', 10))
        ->toThrow(InvalidPayable::class, 'An open dispute already exists for this item.');

    // ResolveDispute validation branches
    expect(fn () => $resolveDispute->handle($dispute1, '', true))
        ->toThrow(InvalidArgumentException::class, 'Only open disputes can be resolved with a resolution.');

    $resolvedDispute = $resolveDispute->handle($dispute1, 'Credit memo received', true);
    expect($resolvedDispute->status)->toBe(DisputeStatus::Resolved)
        ->and($resolvedDispute->resolution)->toBe('Credit memo received')
        ->and($resolvedDispute->resolved_at)->not->toBeNull()
        ->and($resolvedDispute->openItem->status)->toBe(PayableStatus::Open);

    // Cannot resolve already resolved dispute
    expect(fn () => $resolveDispute->handle($resolvedDispute, 'Again', false))
        ->toThrow(InvalidArgumentException::class);

    // Open and reject a second dispute
    $dispute2 = $openDispute->handle($item1->fresh(), 'Price mismatch', 40.00);
    $rejectedDispute = $resolveDispute->handle($dispute2, 'Contract confirms price', false);
    expect($rejectedDispute->status)->toBe(DisputeStatus::Rejected)
        ->and($rejectedDispute->openItem->status)->toBe(PayableStatus::Open);

    // Apply unassigned payment to fully settle item1 (outstanding 100)
    $settledPayment = $applyPayment->handle($unassignedPayment, $item1->fresh(), 100.00);
    expect($settledPayment->status)->toBe(PayableStatus::Applied)
        ->and($settledPayment->unapplied())->toBe(0.0)
        ->and($item1->fresh()->status)->toBe(PayableStatus::Settled)
        ->and($item1->fresh()->outstanding())->toBe(0.0);

    // Settled item cannot be disputed
    expect(fn () => $openDispute->handle($item1->fresh(), 'Too late'))
        ->toThrow(InvalidPayable::class);

    // Create items across aging buckets for supplier2
    $asOf = CarbonImmutable::parse('2026-06-01');
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-CURR-NULL',
        'issued_on' => '2026-05-20',
        'due_on' => null,
        'original_amount' => 10,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-CURR-FUTURE',
        'issued_on' => '2026-05-20',
        'due_on' => '2026-06-10',
        'original_amount' => 20,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-1-30',
        'issued_on' => '2026-05-01',
        'due_on' => '2026-05-15',
        'original_amount' => 30,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-31-60',
        'issued_on' => '2026-04-01',
        'due_on' => '2026-04-15',
        'original_amount' => 40,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-61-90',
        'issued_on' => '2026-03-01',
        'due_on' => '2026-03-15',
        'original_amount' => 50,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $supplier2->id,
        'reference' => 'AGE-OVER-90',
        'issued_on' => '2026-01-01',
        'due_on' => '2026-01-15',
        'original_amount' => 60,
        'currency' => 'USD',
    ]);

    expect(app(AgingQuery::class)->handle($supplier2->id, $asOf))->toBe([
        'current' => 30.0,
        '1_30' => 30.0,
        '31_60' => 40.0,
        '61_90' => 50.0,
        'over_90' => 60.0,
    ]);
    expect(app(AgingQuery::class)->handle(null, $asOf)['over_90'])->toBe(60.0);

    $recon = app(ControlAccountReconciliationQuery::class)->handle();
    expect($recon['subledger_balance'])->toBe(210.0)
        ->and($recon['accounts'])->toHaveCount(2);

    $subledger = app(SupplierSubledgerQuery::class)->handle($supplier2->id);
    expect($subledger['open_items'])->toHaveCount(6)
        ->and($subledger['payments'])->toHaveCount(1)
        ->and($subledger['balance'])->toBe(210.0);

    $recalcBalance->handle($supplier->id);
    expect((float) PayableAccount::where('party_id', $supplier->id)->value('current_balance'))->toBe(0.0);

    // Event construction coverage
    Event::fake();
    $events = [
        new OpenItemCreated($item1),
        new PaymentRecorded($supplierPayment),
        new PaymentApplied($supplierPayment, $item1, 100.0),
        new DisputeOpened($dispute1),
        new DisputeResolved($resolvedDispute),
        new PaymentControlChanged($account),
    ];
    foreach ($events as $ev) {
        event($ev);
        Event::assertDispatched($ev::class);
    }
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Accounting\AccountsReceivable\AccountsReceivableServiceProvider;
use Liberu\Accounting\AccountsReceivable\Actions\ApplyReceipt;
use Liberu\Accounting\AccountsReceivable\Actions\CreateOpenItem;
use Liberu\Accounting\AccountsReceivable\Actions\EnsureAccount;
use Liberu\Accounting\AccountsReceivable\Actions\OpenDispute;
use Liberu\Accounting\AccountsReceivable\Actions\RecalculateAccountBalance;
use Liberu\Accounting\AccountsReceivable\Actions\RecordReceipt;
use Liberu\Accounting\AccountsReceivable\Actions\ResolveDispute;
use Liberu\Accounting\AccountsReceivable\Actions\SetCreditControl;
use Liberu\Accounting\AccountsReceivable\Actions\SyncFinalizedInvoice;
use Liberu\Accounting\AccountsReceivable\Enums\DisputeStatus;
use Liberu\Accounting\AccountsReceivable\Enums\ReceivableStatus;
use Liberu\Accounting\AccountsReceivable\Events\CreditControlChanged;
use Liberu\Accounting\AccountsReceivable\Events\DisputeOpened;
use Liberu\Accounting\AccountsReceivable\Events\DisputeResolved;
use Liberu\Accounting\AccountsReceivable\Events\OpenItemCreated;
use Liberu\Accounting\AccountsReceivable\Events\ReceiptApplied;
use Liberu\Accounting\AccountsReceivable\Events\ReceiptRecorded;
use Liberu\Accounting\AccountsReceivable\Exceptions\InvalidReceivable;
use Liberu\Accounting\AccountsReceivable\Listeners\SyncFinalizedInvoiceListener;
use Liberu\Accounting\AccountsReceivable\Models\ReceiptApplication;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableAccount;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableDispute;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableOpenItem;
use Liberu\Accounting\AccountsReceivable\Queries\AgingQuery;
use Liberu\Accounting\AccountsReceivable\Queries\ControlAccountReconciliationQuery;
use Liberu\Accounting\AccountsReceivable\Queries\CustomerSubledgerQuery;
use Liberu\Accounting\AccountsReceivable\Queries\StatementQuery;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SalesInvoicing\Enums\InvoiceStatus;
use Liberu\Accounting\SalesInvoicing\Events\InvoiceFinalized;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoice;

function createArCustomer(LegalEntity $entity, string $name = 'Acme Customer'): Party
{
    return Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'name' => $name,
    ]);
}

it('boots the service provider and covers enums, exception, events, listener, models, actions, and queries', function (): void {
    $provider = $this->app->register(AccountsReceivableServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountsReceivableServiceProvider::class);

    expect(array_map(fn (DisputeStatus $s): string => $s->value, DisputeStatus::cases()))
        ->toBe(['open', 'resolved', 'rejected']);
    expect(array_map(fn (ReceivableStatus $s): string => $s->value, ReceivableStatus::cases()))
        ->toBe(['open', 'partial', 'settled', 'unapplied', 'applied', 'disputed']);

    $exception = new InvalidReceivable('Bad receivable');
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toBe('Bad receivable');

    $entity = LegalEntity::create([
        'name' => 'AR Test Entity',
        'currency_code' => 'USD',
    ]);
    $customer = createArCustomer($entity, 'Customer One');
    $customer2 = createArCustomer($entity, 'Customer Two');
    $supplier = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Supplier,
        'name' => 'Not A Customer',
    ]);

    $createOpenItem = app(CreateOpenItem::class);
    $recordReceipt = app(RecordReceipt::class);
    $applyReceipt = app(ApplyReceipt::class);
    $openDispute = app(OpenDispute::class);
    $resolveDispute = app(ResolveDispute::class);
    $setCreditControl = app(SetCreditControl::class);
    $ensureAccount = app(EnsureAccount::class);
    $recalcBalance = app(RecalculateAccountBalance::class);
    $syncInvoice = app(SyncFinalizedInvoice::class);

    // Empty queries
    expect(app(AgingQuery::class)->handle())->toBe([
        'current' => 0.0,
        '1_30' => 0.0,
        '31_60' => 0.0,
        '61_90' => 0.0,
        'over_90' => 0.0,
    ]);
    expect(app(CustomerSubledgerQuery::class)->handle($customer->id)['balance'])->toBe(0.0);
    expect(app(StatementQuery::class)->handle($customer->id)['closing_balance'])->toBe(0.0);

    // CreateOpenItem validation branches
    expect(fn () => $createOpenItem->handle([
        'party_id' => null,
        'reference' => 'INV-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidReceivable::class, 'An open item requires a party, reference, positive amount, and currency.');

    expect(fn () => $createOpenItem->handle([
        'party_id' => $customer->id,
        'reference' => '',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidReceivable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $customer->id,
        'reference' => 'INV-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 0,
        'currency' => 'USD',
    ]))->toThrow(InvalidReceivable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $customer->id,
        'reference' => 'INV-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => '',
    ]))->toThrow(InvalidReceivable::class);

    expect(fn () => $createOpenItem->handle([
        'party_id' => $supplier->id,
        'reference' => 'INV-1',
        'issued_on' => '2026-01-01',
        'original_amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidReceivable::class, 'Open items can only be created for an existing customer.');

    // CreateOpenItem happy path
    $item1 = $createOpenItem->handle([
        'party_id' => $customer->id,
        'source_type' => 'Manual',
        'source_id' => '1',
        'reference' => 'INV-001',
        'issued_on' => '2026-01-05',
        'due_on' => '2026-02-04',
        'original_amount' => 300.00,
        'currency' => 'USD',
        'metadata' => ['channel' => 'b2b'],
    ]);

    expect($item1->status)->toBe(ReceivableStatus::Open)
        ->and($item1->outstanding())->toBe(300.0)
        ->and($item1->party->id)->toBe($customer->id);

    $account = $ensureAccount->handle($customer->id);
    expect((float) $account->current_balance)->toBe(300.0)
        ->and($account->party->id)->toBe($customer->id)
        ->and($account->openItems)->toHaveCount(1)
        ->and($account->isOverLimit())->toBeFalse();

    // SetCreditControl validation and happy path
    expect(fn () => $setCreditControl->handle($customer->id, -10.0))
        ->toThrow(InvalidArgumentException::class, 'Credit limit cannot be negative.');
    expect(fn () => $setCreditControl->handle($supplier->id, 100.0))
        ->toThrow(InvalidReceivable::class, 'Credit control can only be configured for an existing customer.');

    $account = $setCreditControl->handle($customer->id, 300.0, true, 'Over limit');
    expect((float) $account->credit_limit)->toBe(300.0)
        ->and($account->credit_hold)->toBeTrue()
        ->and($account->hold_reason)->toBe('Over limit')
        ->and($account->isOverLimit())->toBeTrue();

    $account = $setCreditControl->handle($customer->id, 500.0, false, null);
    expect($account->isOverLimit())->toBeFalse()
        ->and($account->credit_hold)->toBeFalse();

    // RecordReceipt validation branches
    expect(fn () => $recordReceipt->handle(['amount' => 0, 'currency' => 'USD']))
        ->toThrow(InvalidReceivable::class, 'A receipt requires a positive amount and currency.');
    expect(fn () => $recordReceipt->handle(['amount' => 100, 'currency' => 'US']))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $recordReceipt->handle(['party_id' => $supplier->id, 'amount' => 100, 'currency' => 'USD']))
        ->toThrow(InvalidReceivable::class, 'Receipts can only be assigned to an existing customer.');

    // Unassigned receipt + assigned receipts
    $unassignedReceipt = $recordReceipt->handle([
        'amount' => 100.00,
        'currency' => 'USD',
        'reference' => 'RCPT-UNASSIGNED',
    ]);
    expect($unassignedReceipt->party_id)->toBeNull()
        ->and($unassignedReceipt->unapplied())->toBe(100.0);

    $customerReceipt = $recordReceipt->handle([
        'party_id' => $customer->id,
        'received_on' => '2026-01-20',
        'amount' => 250.00,
        'currency' => 'USD',
        'reference' => 'RCPT-1',
    ]);
    expect($customerReceipt->party->id)->toBe($customer->id)
        ->and($customerReceipt->status)->toBe(ReceivableStatus::Unapplied);

    $otherCurrencyReceipt = $recordReceipt->handle([
        'party_id' => $customer->id,
        'amount' => 50.00,
        'currency' => 'EUR',
    ]);
    $otherCustomerReceipt = $recordReceipt->handle([
        'party_id' => $customer2->id,
        'amount' => 50.00,
        'currency' => 'USD',
    ]);

    // ApplyReceipt validation branches
    expect(fn () => $applyReceipt->handle($customerReceipt, $item1, 0))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $applyReceipt->handle($otherCustomerReceipt, $item1, 10))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $applyReceipt->handle($otherCurrencyReceipt, $item1, 10))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $applyReceipt->handle($customerReceipt, $item1, 400))
        ->toThrow(InvalidReceivable::class);

    // Partial receipt application
    $applied = $applyReceipt->handle($customerReceipt, $item1, 200.00);
    expect($applied->status)->toBe(ReceivableStatus::Partial)
        ->and($applied->unapplied())->toBe(50.0)
        ->and($applied->applications)->toHaveCount(1);

    $application = $applied->applications->first();
    expect($application)->toBeInstanceOf(ReceiptApplication::class)
        ->and($application->receipt->id)->toBe($customerReceipt->id)
        ->and($application->openItem->id)->toBe($item1->id);

    $item1->refresh();
    expect($item1->status)->toBe(ReceivableStatus::Partial)
        ->and($item1->outstanding())->toBe(100.0)
        ->and((float) $account->fresh()->current_balance)->toBe(100.0);

    // Duplicate application blocked
    expect(fn () => $applyReceipt->handle($customerReceipt, $item1, 50.00))
        ->toThrow(InvalidReceivable::class, 'A receipt can only be applied to the same open item once.');

    // Applying > item->outstanding() blocked
    $bigReceipt = $recordReceipt->handle([
        'party_id' => $customer->id,
        'amount' => 500.00,
        'currency' => 'USD',
    ]);
    expect(fn () => $applyReceipt->handle($bigReceipt, $item1, 150.00))
        ->toThrow(InvalidReceivable::class);

    // Dispute flow
    expect(fn () => $openDispute->handle($item1, '   '))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $openDispute->handle($item1, 'Overbilled', 0))
        ->toThrow(InvalidReceivable::class);
    expect(fn () => $openDispute->handle($item1, 'Overbilled', 150))
        ->toThrow(InvalidReceivable::class);

    $dispute1 = $openDispute->handle($item1, 'Short shipment');
    expect($dispute1)->toBeInstanceOf(ReceivableDispute::class)
        ->and((float) $dispute1->amount)->toBe(100.0)
        ->and($dispute1->status)->toBe(DisputeStatus::Open)
        ->and($dispute1->openItem->id)->toBe($item1->id)
        ->and($item1->fresh()->status)->toBe(ReceivableStatus::Disputed)
        ->and($item1->disputes)->toHaveCount(1);

    // Second open dispute blocked
    expect(fn () => $openDispute->handle($item1->fresh(), 'Duplicate dispute', 20))
        ->toThrow(InvalidReceivable::class, 'An open dispute already exists for this item.');

    // ResolveDispute validation & accept/reject branches
    expect(fn () => $resolveDispute->handle($dispute1, '', true))
        ->toThrow(InvalidArgumentException::class, 'Only open disputes can be resolved with a resolution.');

    $resolvedDispute = $resolveDispute->handle($dispute1, 'Credit issued', true);
    expect($resolvedDispute->status)->toBe(DisputeStatus::Resolved)
        ->and($resolvedDispute->resolved_at)->not->toBeNull()
        ->and(ReceivableOpenItem::find($item1->id)?->status)->toBe(ReceivableStatus::Open);

    expect(fn () => $resolveDispute->handle($resolvedDispute, 'Already closed', false))
        ->toThrow(InvalidArgumentException::class);

    $dispute2 = $openDispute->handle($item1->fresh(), 'Fee dispute', 25.0);
    $rejectedDispute = $resolveDispute->handle($dispute2, 'Fee valid per contract', false);
    expect($rejectedDispute->status)->toBe(DisputeStatus::Rejected)
        ->and(ReceivableOpenItem::find($item1->id)?->status)->toBe(ReceivableStatus::Open);

    // Settle item1 completely using unassigned receipt
    $fullyApplied = $applyReceipt->handle($unassignedReceipt, $item1->fresh(), 100.00);
    expect($fullyApplied->status)->toBe(ReceivableStatus::Applied)
        ->and($fullyApplied->unapplied())->toBe(0.0)
        ->and($item1->fresh()->status)->toBe(ReceivableStatus::Settled)
        ->and($item1->fresh()->outstanding())->toBe(0.0);

    // Settled item cannot be disputed
    expect(fn () => $openDispute->handle($item1->fresh(), 'Too late'))
        ->toThrow(InvalidReceivable::class);

    // StatementQuery with opening, period items, and receipts
    $createOpenItem->handle([
        'party_id' => $customer->id,
        'reference' => 'INV-002',
        'issued_on' => '2026-02-10',
        'due_on' => '2026-03-10',
        'original_amount' => 150.00,
        'currency' => 'USD',
    ]);
    $statement = app(StatementQuery::class)->handle(
        $customer->id,
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-02-28')
    );
    expect($statement['opening_balance'])->toBe(100.0)
        ->and($statement['charges'])->toBe(150.0)
        ->and($statement['payments'])->toBe(0.0)
        ->and($statement['closing_balance'])->toBe(250.0)
        ->and($statement['items'])->toHaveCount(1)
        ->and($statement['receipts'])->toHaveCount(0);

    // AgingQuery across all buckets for customer2
    $asOf = CarbonImmutable::parse('2026-06-01');
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-CURR-NULL',
        'issued_on' => '2026-05-20',
        'due_on' => null,
        'original_amount' => 10,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-CURR-FUT',
        'issued_on' => '2026-05-20',
        'due_on' => '2026-06-15',
        'original_amount' => 20,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-1-30',
        'issued_on' => '2026-05-01',
        'due_on' => '2026-05-15',
        'original_amount' => 30,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-31-60',
        'issued_on' => '2026-04-01',
        'due_on' => '2026-04-15',
        'original_amount' => 40,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-61-90',
        'issued_on' => '2026-03-01',
        'due_on' => '2026-03-15',
        'original_amount' => 50,
        'currency' => 'USD',
    ]);
    $createOpenItem->handle([
        'party_id' => $customer2->id,
        'reference' => 'AR-OVER-90',
        'issued_on' => '2026-01-01',
        'due_on' => '2026-01-15',
        'original_amount' => 60,
        'currency' => 'USD',
    ]);

    expect(app(AgingQuery::class)->handle($customer2->id, $asOf))->toBe([
        'current' => 30.0,
        '1_30' => 30.0,
        '31_60' => 40.0,
        '61_90' => 50.0,
        'over_90' => 60.0,
    ]);
    expect(app(AgingQuery::class)->handle(null, $asOf)['over_90'])->toBe(60.0)
        ->and(app(AgingQuery::class)->handle(null, $asOf)['61_90'])->toBe(200.0);

    // SyncFinalizedInvoice & Listener (via event dispatch and direct idempotent call)
    $invoice = SalesInvoice::create([
        'invoice_number' => 'SINV-2026-0001',
        'party_id' => $customer->id,
        'invoice_date' => '2026-02-15',
        'due_on' => '2026-03-15',
        'status' => InvoiceStatus::Final,
        'subtotal' => 400.00,
        'discount_total' => 0.00,
        'tax_total' => 0.00,
        'total' => 400.00,
        'currency' => 'USD',
    ]);

    event(new InvoiceFinalized($invoice));

    $syncedItem = ReceivableOpenItem::query()
        ->where('source_type', SalesInvoice::class)
        ->where('source_id', (string) $invoice->id)
        ->first();
    expect($syncedItem)->not->toBeNull()
        ->and($syncedItem->reference)->toBe('SINV-2026-0001')
        ->and((float) $syncedItem->original_amount)->toBe(400.0);

    // Idempotent second call returns existing item without error
    $existingAgain = $syncInvoice->handle($invoice);
    expect($existingAgain->id)->toBe($syncedItem->id);
    app(SyncFinalizedInvoiceListener::class)->handle(new InvoiceFinalized($invoice));

    // CustomerSubledgerQuery
    $subledger = app(CustomerSubledgerQuery::class)->handle($customer->id);
    expect($subledger['open_items'])->toHaveCount(3)
        ->and($subledger['receipts'])->toHaveCount(3)
        ->and($subledger['balance'])->toBe(550.0);

    // ControlAccountReconciliationQuery with explicit balance and via GL tables
    $reconExplicit = app(ControlAccountReconciliationQuery::class)->handle(760.0);
    expect($reconExplicit)->toBe([
        'subledger_balance' => 760.0,
        'control_account_balance' => 760.0,
        'difference' => 0.0,
        'is_reconciled' => true,
    ]);

    // Auto-lookup from chart_accounts + journal_lines when null passed
    $book = Book::create([
        'legal_entity_id' => $entity->id,
        'name' => 'Main Book',
        'code' => 'MAIN',
    ]);
    $arChartId = DB::table('accounting_chart_accounts')->insertGetId([
        'legal_entity_id' => $entity->id,
        'code' => 'accounts_receivable',
        'name' => 'Accounts Receivable',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_control_account' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $jeId = DB::table('accounting_journal_entries')->insertGetId([
        'book_id' => $book->id,
        'entry_number' => 'JE-100',
        'entry_date' => '2026-02-15',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('accounting_journal_lines')->insert([
        'journal_entry_id' => $jeId,
        'account_id' => $arChartId,
        'debit' => 700.00,
        'credit' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reconAuto = app(ControlAccountReconciliationQuery::class)->handle();
    expect($reconAuto['subledger_balance'])->toBe(760.0)
        ->and($reconAuto['control_account_balance'])->toBe(700.0)
        ->and($reconAuto['difference'])->toBe(60.0)
        ->and($reconAuto['is_reconciled'])->toBeFalse();

    $recalcBalance->handle($customer->id);
    expect((float) ReceivableAccount::where('party_id', $customer->id)->value('current_balance'))->toBe(550.0);

    // Event construction & dispatch coverage
    Event::fake();
    $events = [
        new OpenItemCreated($item1),
        new ReceiptRecorded($customerReceipt),
        new ReceiptApplied($customerReceipt, $item1, 200.0),
        new DisputeOpened($dispute1),
        new DisputeResolved($resolvedDispute),
        new CreditControlChanged($account),
    ];
    foreach ($events as $ev) {
        event($ev);
        Event::assertDispatched($ev::class);
    }
});

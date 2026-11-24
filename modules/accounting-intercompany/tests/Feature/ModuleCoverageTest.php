<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Intercompany\Actions\AddTransferPricingEvidence;
use Liberu\Accounting\Intercompany\Actions\ConfigureTradingRule;
use Liberu\Accounting\Intercompany\Actions\ConfirmTransaction;
use Liberu\Accounting\Intercompany\Actions\CreateCounterparty;
use Liberu\Accounting\Intercompany\Actions\CreateTransaction;
use Liberu\Accounting\Intercompany\Actions\ReconcileIntercompany;
use Liberu\Accounting\Intercompany\Actions\RecordDifference;
use Liberu\Accounting\Intercompany\Actions\SettleTransaction;
use Liberu\Accounting\Intercompany\Enums\ConfirmationStatus;
use Liberu\Accounting\Intercompany\Enums\TransactionStatus;
use Liberu\Accounting\Intercompany\Exceptions\InvalidIntercompany;
use Liberu\Accounting\Intercompany\IntercompanyServiceProvider;
use Liberu\Accounting\Intercompany\Models\IntercompanyConfirmation;
use Liberu\Accounting\Intercompany\Models\IntercompanyCounterparty;
use Liberu\Accounting\Intercompany\Models\IntercompanyDifference;
use Liberu\Accounting\Intercompany\Models\IntercompanyReconciliation;
use Liberu\Accounting\Intercompany\Models\IntercompanySettlement;
use Liberu\Accounting\Intercompany\Models\IntercompanyTransaction;
use Liberu\Accounting\Intercompany\Models\TradingRule;
use Liberu\Accounting\Intercompany\Models\TransferPricingEvidence;
use Liberu\Accounting\Intercompany\Queries\IntercompanyQuery;

it('registers and boots the intercompany service provider and migrations', function (): void {
    $provider = app()->getProvider(IntercompanyServiceProvider::class)
        ?? new IntercompanyServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(IntercompanyServiceProvider::class)
        ->and(app(IntercompanyQuery::class))->toBe(app(IntercompanyQuery::class))
        ->and(Schema::hasTable('accounting_intercompany_counterparties'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_trading_rules'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_transactions'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_confirmations'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_settlements'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_differences'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_transfer_pricing_evidence'))->toBeTrue()
        ->and(Schema::hasTable('accounting_intercompany_reconciliations'))->toBeTrue();
});

it('exposes intercompany enums and exception class', function (): void {
    expect(array_map(static fn (ConfirmationStatus $s): string => $s->value, ConfirmationStatus::cases()))
        ->toBe(['pending', 'confirmed', 'rejected'])
        ->and(array_map(static fn (TransactionStatus $s): string => $s->value, TransactionStatus::cases()))
        ->toBe(['draft', 'pending_confirmation', 'confirmed', 'settled', 'disputed', 'cancelled']);

    $exception = new InvalidIntercompany('Invalid intercompany operation.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid intercompany operation.');
});

it('creates counterparties and configures trading rules with validation', function (): void {
    $createCounterparty = app(CreateCounterparty::class);
    foreach (['entity_ref', 'counterparty_ref', 'name', 'default_currency'] as $missing) {
        $payload = [
            'team_id' => 10,
            'entity_ref' => 'ENT-UK',
            'counterparty_ref' => 'ENT-DE',
            'name' => 'Germany Sub',
            'default_currency' => 'eur',
        ];
        unset($payload[$missing]);
        expect(fn () => $createCounterparty->handle($payload))
            ->toThrow(InvalidIntercompany::class, "Missing counterparty field [{$missing}].");
    }

    $counterparty = $createCounterparty->handle([
        'team_id' => 10,
        'entity_ref' => 'ENT-UK',
        'counterparty_ref' => 'ENT-DE',
        'name' => 'Germany Sub',
        'default_currency' => 'eur',
        'metadata' => ['region' => 'EU'],
    ]);

    expect($counterparty)->toBeInstanceOf(IntercompanyCounterparty::class)
        ->and($counterparty->default_currency)->toBe('EUR')
        ->and($counterparty->active)->toBeTrue()
        ->and($counterparty->metadata)->toBe(['region' => 'EU']);

    $configureRule = app(ConfigureTradingRule::class);
    foreach (['rule_ref', 'description', 'pricing_method', 'currency'] as $missing) {
        $payload = [
            'rule_ref' => 'RULE-1',
            'description' => 'Cost plus 5%',
            'pricing_method' => 'cost_plus',
            'markup_percent' => 5.0,
            'currency' => 'eur',
        ];
        unset($payload[$missing]);
        expect(fn () => $configureRule->handle($counterparty, $payload))
            ->toThrow(InvalidIntercompany::class, "Missing rule field [{$missing}].");
    }

    expect(fn () => $configureRule->handle($counterparty, [
        'rule_ref' => 'RULE-NEG',
        'description' => 'Negative',
        'pricing_method' => 'cost_plus',
        'markup_percent' => -1,
        'currency' => 'eur',
    ]))->toThrow(InvalidIntercompany::class, 'Markup cannot be negative.');

    $rule = $configureRule->handle($counterparty, [
        'rule_ref' => 'RULE-1',
        'description' => 'Cost plus 5%',
        'pricing_method' => 'cost_plus',
        'markup_percent' => 5.25,
        'currency' => 'eur',
        'active' => false,
        'metadata' => ['approved' => true],
    ]);

    expect($rule)->toBeInstanceOf(TradingRule::class)
        ->and($rule->counterparty->is($counterparty))->toBeTrue()
        ->and($rule->currency)->toBe('EUR')
        ->and((float) $rule->markup_percent)->toBe(5.25)
        ->and($rule->active)->toBeFalse()
        ->and($rule->metadata)->toBe(['approved' => true])
        ->and($counterparty->rules)->toHaveCount(1);
});

it('creates, confirms, rejects, settles, records differences, and attaches transfer pricing evidence', function (): void {
    Carbon::setTestNow('2026-08-29 12:00:00');

    $counterparty = app(CreateCounterparty::class)->handle([
        'team_id' => 20,
        'entity_ref' => 'ENT-A',
        'counterparty_ref' => 'ENT-B',
        'name' => 'Entity B',
        'default_currency' => 'GBP',
    ]);

    $createTx = app(CreateTransaction::class);
    foreach (['transaction_ref', 'source_entity_ref', 'target_entity_ref', 'transaction_type', 'description', 'currency'] as $missing) {
        $payload = [
            'team_id' => 20,
            'transaction_ref' => 'IC-1',
            'source_entity_ref' => 'ENT-A',
            'target_entity_ref' => 'ENT-B',
            'transaction_type' => 'management_fee',
            'description' => 'Q3 Fee',
            'amount' => 1000,
            'currency' => 'gbp',
        ];
        unset($payload[$missing]);
        expect(fn () => $createTx->handle($counterparty, $payload))
            ->toThrow(InvalidIntercompany::class, "Missing transaction field [{$missing}].");
    }

    expect(fn () => $createTx->handle($counterparty, [
        'team_id' => 20, 'transaction_ref' => 'IC-ZERO', 'source_entity_ref' => 'ENT-A', 'target_entity_ref' => 'ENT-B',
        'transaction_type' => 'fee', 'description' => 'Zero', 'amount' => 0, 'currency' => 'gbp',
    ]))->toThrow(InvalidIntercompany::class, 'Transaction amount must be positive and entities must differ.');

    expect(fn () => $createTx->handle($counterparty, [
        'team_id' => 20, 'transaction_ref' => 'IC-SAME', 'source_entity_ref' => 'ENT-A', 'target_entity_ref' => 'ENT-A',
        'transaction_type' => 'fee', 'description' => 'Same', 'amount' => 100, 'currency' => 'gbp',
    ]))->toThrow(InvalidIntercompany::class, 'Transaction amount must be positive and entities must differ.');

    $tx1 = $createTx->handle($counterparty, [
        'team_id' => 20,
        'transaction_ref' => 'IC-100',
        'source_entity_ref' => 'ENT-A',
        'target_entity_ref' => 'ENT-B',
        'transaction_type' => 'management_fee',
        'description' => 'Q3 Management Fee',
        'amount' => 1000.00,
        'currency' => 'gbp',
        'metadata' => ['quarter' => 'Q3'],
    ]);

    expect($tx1)->toBeInstanceOf(IntercompanyTransaction::class)
        ->and($tx1->counterparty->is($counterparty))->toBeTrue()
        ->and($tx1->status)->toBe(TransactionStatus::PendingConfirmation)
        ->and($tx1->currency)->toBe('GBP')
        ->and($tx1->metadata)->toBe(['quarter' => 'Q3'])
        ->and($counterparty->transactions)->toHaveCount(1);

    // Cannot settle before confirmation
    $settle = app(SettleTransaction::class);
    expect(fn () => $settle->handle($tx1, ['settlement_ref' => 'SET-1', 'source_ref' => 'BANK-1', 'amount' => 1000]))
        ->toThrow(InvalidIntercompany::class, 'Only confirmed transactions can be settled.');

    // Confirm happy path
    $confirm = app(ConfirmTransaction::class);
    $tx1 = $confirm->handle($tx1, 'ENT-B', true, 'Agreed');

    expect($tx1->status)->toBe(TransactionStatus::Confirmed)
        ->and($tx1->confirmations)->toHaveCount(1)
        ->and($tx1->confirmations->first())->toBeInstanceOf(IntercompanyConfirmation::class)
        ->and($tx1->confirmations->first()->status)->toBe(ConfirmationStatus::Confirmed)
        ->and($tx1->confirmations->first()->transaction->is($tx1))->toBeTrue();

    // Cannot re-confirm non-pending
    expect(fn () => $confirm->handle($tx1, 'ENT-B', true))
        ->toThrow(InvalidIntercompany::class, 'Only pending transactions can be confirmed.');

    // Settle validation & happy path
    expect(fn () => $settle->handle($tx1, ['settlement_ref' => 'SET-1', 'source_ref' => 'BANK-1', 'amount' => 0]))
        ->toThrow(InvalidIntercompany::class, 'Settlement amount or references are invalid.');
    expect(fn () => $settle->handle($tx1, ['settlement_ref' => 'SET-1', 'source_ref' => 'BANK-1', 'amount' => 1500]))
        ->toThrow(InvalidIntercompany::class, 'Settlement amount or references are invalid.');
    expect(fn () => $settle->handle($tx1, ['source_ref' => 'BANK-1', 'amount' => 500]))
        ->toThrow(InvalidIntercompany::class, 'Settlement amount or references are invalid.');
    expect(fn () => $settle->handle($tx1, ['settlement_ref' => 'SET-1', 'amount' => 500]))
        ->toThrow(InvalidIntercompany::class, 'Settlement amount or references are invalid.');

    $tx1 = $settle->handle($tx1, [
        'settlement_ref' => 'SET-100',
        'source_ref' => 'BANK-100',
        'amount' => 600.00,
    ]);

    expect($tx1->status)->toBe(TransactionStatus::Settled)
        ->and($tx1->settlements)->toHaveCount(1)
        ->and($tx1->settlements->first())->toBeInstanceOf(IntercompanySettlement::class)
        ->and($tx1->settlements->first()->currency)->toBe('GBP')
        ->and($tx1->settlements->first()->transaction->is($tx1))->toBeTrue();

    // Rejection & difference flow
    $tx2 = $createTx->handle($counterparty, [
        'team_id' => 20,
        'transaction_ref' => 'IC-101',
        'source_entity_ref' => 'ENT-A',
        'target_entity_ref' => 'ENT-B',
        'transaction_type' => 'recharge',
        'description' => 'IT Recharge',
        'amount' => 500.00,
        'currency' => 'gbp',
    ]);

    expect(fn () => $confirm->handle($tx2, 'ENT-B', false, '  '))
        ->toThrow(InvalidIntercompany::class, 'A rejected confirmation requires a comment.');

    $tx2 = $confirm->handle($tx2, 'ENT-B', false, 'Disputed hours');
    expect($tx2->status)->toBe(TransactionStatus::Disputed)
        ->and($tx2->confirmations->first()->status)->toBe(ConfirmationStatus::Rejected);

    // Record difference
    $recordDiff = app(RecordDifference::class);
    expect(fn () => $recordDiff->handle($tx2, ['difference_ref' => 'D-1', 'reason' => 'FX', 'amount' => 0]))
        ->toThrow(InvalidIntercompany::class, 'Difference requires a positive amount, reference and reason.');
    expect(fn () => $recordDiff->handle($tx2, ['reason' => 'FX', 'amount' => 20]))
        ->toThrow(InvalidIntercompany::class, 'Difference requires a positive amount, reference and reason.');
    expect(fn () => $recordDiff->handle($tx2, ['difference_ref' => 'D-1', 'amount' => 20]))
        ->toThrow(InvalidIntercompany::class, 'Difference requires a positive amount, reference and reason.');

    $diff = $recordDiff->handle($tx2, [
        'difference_ref' => 'DIFF-1',
        'amount' => 25.50,
        'reason' => 'FX rounding',
        'actor_ref' => 'controller',
    ]);

    expect($diff)->toBeInstanceOf(IntercompanyDifference::class)
        ->and($diff->transaction->is($tx2))->toBeTrue()
        ->and((float) $diff->amount)->toBe(25.5)
        ->and($diff->status)->toBe('open')
        ->and($tx2->differences)->toHaveCount(1);

    // Transfer pricing evidence
    $addEvidence = app(AddTransferPricingEvidence::class);
    foreach (['evidence_ref', 'kind', 'currency'] as $missing) {
        $payload = [
            'evidence_ref' => 'TP-1',
            'kind' => 'benchmark_study',
            'currency' => 'gbp',
        ];
        unset($payload[$missing]);
        expect(fn () => $addEvidence->handle($tx1, $payload))
            ->toThrow(InvalidIntercompany::class, "Missing evidence field [{$missing}].");
    }

    $evidence = $addEvidence->handle($tx1, [
        'evidence_ref' => 'TP-1',
        'kind' => 'benchmark_study',
        'file_ref' => 'tp/2026-study.pdf',
        'description' => 'OECD benchmark',
        'arm_length_value' => 995.00,
        'currency' => 'gbp',
        'metadata' => ['year' => 2026],
    ]);

    expect($evidence)->toBeInstanceOf(TransferPricingEvidence::class)
        ->and($evidence->transaction->is($tx1))->toBeTrue()
        ->and($evidence->currency)->toBe('GBP')
        ->and((float) $evidence->arm_length_value)->toBe(995.0)
        ->and($evidence->metadata)->toBe(['year' => 2026])
        ->and($tx1->evidence)->toHaveCount(1);

    Carbon::setTestNow();
});

it('reconciles intercompany balances and queries transactions and summaries', function (): void {
    $reconcile = app(ReconcileIntercompany::class);
    foreach (['reconciliation_ref', 'period_ref', 'entity_ref', 'counterparty_ref'] as $missing) {
        $payload = [
            'team_id' => 30,
            'reconciliation_ref' => 'REC-1',
            'period_ref' => '2026-08',
            'entity_ref' => 'ENT-1',
            'counterparty_ref' => 'ENT-2',
            'source_total' => 100,
            'counterparty_total' => 100,
        ];
        unset($payload[$missing]);
        expect(fn () => $reconcile->handle($payload))
            ->toThrow(InvalidIntercompany::class, "Missing reconciliation field [{$missing}].");
    }

    expect(fn () => $reconcile->handle([
        'reconciliation_ref' => 'REC-NEG', 'period_ref' => '2026-08', 'entity_ref' => 'ENT-1', 'counterparty_ref' => 'ENT-2',
        'source_total' => -1, 'counterparty_total' => 100,
    ]))->toThrow(InvalidIntercompany::class, 'Reconciliation totals cannot be negative.');

    expect(fn () => $reconcile->handle([
        'reconciliation_ref' => 'REC-NEG2', 'period_ref' => '2026-08', 'entity_ref' => 'ENT-1', 'counterparty_ref' => 'ENT-2',
        'source_total' => 100, 'counterparty_total' => -5,
    ]))->toThrow(InvalidIntercompany::class, 'Reconciliation totals cannot be negative.');

    $matched = $reconcile->handle([
        'team_id' => 30,
        'reconciliation_ref' => 'REC-MATCH',
        'period_ref' => '2026-08',
        'entity_ref' => 'ENT-1',
        'counterparty_ref' => 'ENT-2',
        'transaction_count' => 3,
        'source_total' => 500.00,
        'counterparty_total' => 500.00,
        'metadata' => ['auto' => true],
    ]);

    $variance = $reconcile->handle([
        'team_id' => 30,
        'reconciliation_ref' => 'REC-VAR',
        'period_ref' => '2026-08',
        'entity_ref' => 'ENT-1',
        'counterparty_ref' => 'ENT-3',
        'transaction_count' => 2,
        'source_total' => 500.00,
        'counterparty_total' => 450.25,
        'actor_ref' => 'auditor',
    ]);

    expect($matched)->toBeInstanceOf(IntercompanyReconciliation::class)
        ->and($matched->status)->toBe('reconciled')
        ->and((float) $matched->difference_total)->toBe(0.0)
        ->and($matched->transaction_count)->toBe(3)
        ->and($matched->metadata)->toBe(['auto' => true])
        ->and($variance->status)->toBe('variance')
        ->and((float) $variance->difference_total)->toBe(49.75);

    // IntercompanyQuery
    $query = app(IntercompanyQuery::class);
    expect($query->paginate(999)->total())->toBe(0);

    $cp = app(CreateCounterparty::class)->handle([
        'team_id' => 30,
        'entity_ref' => 'ENT-1',
        'counterparty_ref' => 'ENT-2',
        'name' => 'Entity 2',
        'default_currency' => 'USD',
    ]);

    $tx = app(CreateTransaction::class)->handle($cp, [
        'team_id' => 30,
        'transaction_ref' => 'IC-Q1',
        'source_entity_ref' => 'ENT-1',
        'target_entity_ref' => 'ENT-2',
        'transaction_type' => 'loan',
        'description' => 'Intercompany Loan',
        'amount' => 1000.00,
        'currency' => 'USD',
        'transaction_date' => '2026-08-10 10:00:00',
    ]);

    $tx = app(ConfirmTransaction::class)->handle($tx, 'ENT-2', true);
    $tx = app(SettleTransaction::class)->handle($tx, [
        'settlement_ref' => 'SET-Q1',
        'source_ref' => 'WIRE-1',
        'amount' => 400.00,
    ]);

    $page = $query->paginate(30, TransactionStatus::Settled, 50);
    expect($page->total())->toBe(1)
        ->and($page->items()[0]->transaction_ref)->toBe('IC-Q1');

    $summary = $query->reconciliationSummary($tx);
    expect($summary)->toBe([
        'transaction_amount' => 1000.0,
        'confirmed_amount' => 1000.0,
        'settled_amount' => 400.0,
        'outstanding' => 600.0,
    ]);
});

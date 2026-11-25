<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CorporateCards\Actions\ApproveCardTransaction;
use Liberu\Accounting\CorporateCards\Actions\CodeCardTransaction;
use Liberu\Accounting\CorporateCards\Actions\CreateCardAccount;
use Liberu\Accounting\CorporateCards\Actions\ReconcileCardTransaction;
use Liberu\Accounting\CorporateCards\Actions\RecordCardTransaction;
use Liberu\Accounting\CorporateCards\CorporateCardsServiceProvider;
use Liberu\Accounting\CorporateCards\Enums\CardTransactionStatus;
use Liberu\Accounting\CorporateCards\Exceptions\InvalidCorporateCard;
use Liberu\Accounting\CorporateCards\Models\CardAccount;
use Liberu\Accounting\CorporateCards\Models\CardTransaction;
use Liberu\Accounting\CorporateCards\Queries\CorporateCardQuery;

it('registers the corporate cards service provider and runs migrations', function (): void {
    expect(app()->getProvider(CorporateCardsServiceProvider::class))
        ->toBeInstanceOf(CorporateCardsServiceProvider::class);

    expect(Schema::hasTable('accounting_corporate_card_accounts'))->toBeTrue();
    expect(Schema::hasTable('accounting_corporate_card_transactions'))->toBeTrue();
    expect(Schema::hasColumns('accounting_corporate_card_accounts', [
        'id',
        'team_id',
        'card_ref',
        'holder_ref',
        'provider_ref',
        'currency',
        'limit_amount',
        'spent_amount',
        'status',
        'controls',
    ]))->toBeTrue();
    expect(Schema::hasColumns('accounting_corporate_card_transactions', [
        'id',
        'card_account_id',
        'team_id',
        'transaction_ref',
        'transaction_date',
        'amount',
        'currency',
        'merchant_ref',
        'status',
        'category_ref',
        'receipt_ref',
        'feed_ref',
        'approved_by',
        'approved_at',
        'reconciliation_ref',
        'metadata',
    ]))->toBeTrue();
});

it('exposes all card transaction status enum cases', function (): void {
    expect(array_column(CardTransactionStatus::cases(), 'value'))
        ->toBe(['unassigned', 'coded', 'pending_approval', 'approved', 'reconciled']);
});

it('creates card accounts and validates required fields, positive limit, and uniqueness', function (): void {
    $create = new CreateCardAccount();

    $account = $create->handle([
        'team_id' => 71,
        'card_ref' => 'CARD-001',
        'holder_ref' => 'EMP-10',
        'provider_ref' => 'stripe_issuing',
        'currency' => 'USD',
        'limit_amount' => 5000,
        'controls' => ['daily_limit' => 1000],
    ]);

    expect($account)->toBeInstanceOf(CardAccount::class)
        ->and($account->status)->toBe('active')
        ->and($account->spent_amount)->toBe('0.00000000')
        ->and($account->limit_amount)->toBe('5000.00000000')
        ->and($account->controls)->toBe(['daily_limit' => 1000]);

    foreach (['team_id', 'card_ref', 'holder_ref', 'currency', 'limit_amount'] as $missingField) {
        $payload = [
            'team_id' => 71,
            'card_ref' => 'CARD-ERR',
            'holder_ref' => 'EMP-10',
            'currency' => 'USD',
            'limit_amount' => 1000,
        ];
        unset($payload[$missingField]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidCorporateCard::class, "{$missingField} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 71,
        'card_ref' => 'CARD-ZERO',
        'holder_ref' => 'EMP-10',
        'currency' => 'USD',
        'limit_amount' => 0,
    ]))->toThrow(InvalidCorporateCard::class, 'Card limit must be positive.');

    expect(fn () => $create->handle([
        'team_id' => 71,
        'card_ref' => 'CARD-001',
        'holder_ref' => 'EMP-10',
        'currency' => 'USD',
        'limit_amount' => 1000,
    ]))->toThrow(InvalidCorporateCard::class, 'Card reference already exists.');
});

it('records, codes, approves, and reconciles card transactions and enforces limits and state transitions', function (): void {
    Carbon::setTestNow('2026-08-29 12:00:00');

    $createAccount = new CreateCardAccount();
    $recordTx = new RecordCardTransaction();
    $codeTx = new CodeCardTransaction();
    $approveTx = new ApproveCardTransaction();
    $reconcileTx = new ReconcileCardTransaction();

    $account = $createAccount->handle([
        'team_id' => 72,
        'card_ref' => 'CARD-002',
        'holder_ref' => 'EMP-22',
        'currency' => 'EUR',
        'limit_amount' => 1000,
    ]);

    foreach (['transaction_ref', 'transaction_date', 'amount', 'currency'] as $missingField) {
        $payload = [
            'transaction_ref' => 'TX-ERR',
            'transaction_date' => '2026-08-29',
            'amount' => 100,
            'currency' => 'EUR',
        ];
        unset($payload[$missingField]);

        expect(fn () => $recordTx->handle($account, $payload))
            ->toThrow(InvalidCorporateCard::class, "{$missingField} is required.");
    }

    expect(fn () => $recordTx->handle($account, [
        'transaction_ref' => 'TX-ZERO',
        'transaction_date' => '2026-08-29',
        'amount' => 0,
        'currency' => 'EUR',
    ]))->toThrow(InvalidCorporateCard::class, 'Transaction exceeds the card limit.');

    expect(fn () => $recordTx->handle($account, [
        'transaction_ref' => 'TX-OVER',
        'transaction_date' => '2026-08-29',
        'amount' => 1000.01,
        'currency' => 'EUR',
    ]))->toThrow(InvalidCorporateCard::class, 'Transaction exceeds the card limit.');

    $transaction = $recordTx->handle($account, [
        'transaction_ref' => 'TX-001',
        'transaction_date' => '2026-08-29',
        'amount' => 450.25,
        'currency' => 'EUR',
        'merchant_ref' => 'MERCH-AWS',
        'metadata' => ['mcc' => '7372'],
    ]);

    expect($transaction)->toBeInstanceOf(CardTransaction::class)
        ->and($transaction->status)->toBe(CardTransactionStatus::Unassigned)
        ->and($transaction->amount)->toBe('450.25000000')
        ->and($transaction->transaction_date->toDateString())->toBe('2026-08-29')
        ->and($transaction->metadata)->toBe(['mcc' => '7372'])
        ->and($transaction->cardAccount->id)->toBe($account->id)
        ->and($account->refresh()->spent_amount)->toBe('450.25000000');

    expect(fn () => $recordTx->handle($account, [
        'transaction_ref' => 'TX-002-OVER',
        'transaction_date' => '2026-08-29',
        'amount' => 600,
        'currency' => 'EUR',
    ]))->toThrow(InvalidCorporateCard::class, 'Transaction exceeds the card limit.');

    expect(fn () => $approveTx->handle($transaction, 99))
        ->toThrow(InvalidCorporateCard::class, 'Only coded transactions can be approved.');

    expect(fn () => $reconcileTx->handle($transaction, 'REC-1'))
        ->toThrow(InvalidCorporateCard::class, 'Only approved transactions with a reconciliation reference can be reconciled.');

    expect(fn () => $codeTx->handle($transaction, ''))
        ->toThrow(InvalidCorporateCard::class, 'A category is required and reconciled transactions cannot be changed.');

    $transaction = $codeTx->handle($transaction, '6400-CLOUD', 'RCPT-77');
    expect($transaction->status)->toBe(CardTransactionStatus::PendingApproval)
        ->and($transaction->category_ref)->toBe('6400-CLOUD')
        ->and($transaction->receipt_ref)->toBe('RCPT-77');

    $transaction = $approveTx->handle($transaction, 99);
    expect($transaction->status)->toBe(CardTransactionStatus::Approved)
        ->and($transaction->approved_by)->toBe(99)
        ->and($transaction->approved_at->toDateTimeString())->toBe('2026-08-29 12:00:00');

    expect(fn () => $reconcileTx->handle($transaction, ''))
        ->toThrow(InvalidCorporateCard::class);

    $reconciled = $reconcileTx->handle($transaction, 'REC-2026-08');
    expect($reconciled->status)->toBe(CardTransactionStatus::Reconciled)
        ->and($reconciled->reconciliation_ref)->toBe('REC-2026-08')
        ->and($reconciled->relationLoaded('cardAccount'))->toBeTrue();

    expect(fn () => $codeTx->handle($reconciled, '6500-OTHER'))
        ->toThrow(InvalidCorporateCard::class);

    Carbon::setTestNow();
});

it('queries corporate card accounts with transactions by team', function (): void {
    $createAccount = new CreateCardAccount();
    $recordTx = new RecordCardTransaction();
    $query = new CorporateCardQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $this->travel(-2)->minutes();
    $first = $createAccount->handle([
        'team_id' => 75,
        'card_ref' => 'CARD-A',
        'holder_ref' => 'EMP-1',
        'currency' => 'USD',
        'limit_amount' => 2000,
    ]);
    $recordTx->handle($first, [
        'transaction_ref' => 'TX-A1',
        'transaction_date' => '2026-08-29',
        'amount' => 120,
        'currency' => 'USD',
    ]);

    $this->travel(2)->minutes();
    $second = $createAccount->handle([
        'team_id' => 75,
        'card_ref' => 'CARD-B',
        'holder_ref' => 'EMP-2',
        'currency' => 'USD',
        'limit_amount' => 3000,
    ]);

    $createAccount->handle([
        'team_id' => 76,
        'card_ref' => 'CARD-C',
        'holder_ref' => 'EMP-3',
        'currency' => 'USD',
        'limit_amount' => 4000,
    ]);

    $results = $query->forTeam(75);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($results->firstWhere('id', $first->id)->relationLoaded('transactions'))->toBeTrue()
        ->and($results->firstWhere('id', $first->id)->transactions)->toHaveCount(1);
});

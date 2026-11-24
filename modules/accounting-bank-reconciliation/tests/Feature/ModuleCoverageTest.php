<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\BankReconciliation\Actions\AddReconciliationEntry;
use Liberu\Accounting\BankReconciliation\Actions\ConfirmReconciliationEntry;
use Liberu\Accounting\BankReconciliation\Actions\CreateReconciliationSession;
use Liberu\Accounting\BankReconciliation\Actions\SignOffReconciliation;
use Liberu\Accounting\BankReconciliation\BankReconciliationServiceProvider;
use Liberu\Accounting\BankReconciliation\Enums\ReconciliationEntryKind;
use Liberu\Accounting\BankReconciliation\Enums\ReconciliationEntryStatus;
use Liberu\Accounting\BankReconciliation\Enums\ReconciliationStatus;
use Liberu\Accounting\BankReconciliation\Events\ReconciliationEntryConfirmed;
use Liberu\Accounting\BankReconciliation\Events\ReconciliationSignedOff;
use Liberu\Accounting\BankReconciliation\Exceptions\InvalidReconciliation;
use Liberu\Accounting\BankReconciliation\Models\ReconciliationEntry;
use Liberu\Accounting\BankReconciliation\Models\ReconciliationSession;
use Liberu\Accounting\BankReconciliation\Queries\ReconciliationSummaryQuery;
use Liberu\Accounting\Erp\Models\User;

it('boots bank reconciliation service provider and covers enums and exceptions', function (): void {
    $provider = $this->app->register(BankReconciliationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BankReconciliationServiceProvider::class)
        ->and(array_column(ReconciliationEntryKind::cases(), 'value'))->toBe(['match', 'transfer', 'grouped_receipt', 'fee', 'interest', 'adjustment'])
        ->and(array_column(ReconciliationEntryStatus::cases(), 'value'))->toBe(['suggested', 'confirmed', 'exception'])
        ->and(array_column(ReconciliationStatus::cases(), 'value'))->toBe(['draft', 'in_review', 'signed_off'])
        ->and((new InvalidReconciliation('Bad rec'))->getMessage())->toBe('Bad rec');
});

it('creates sessions, adds and confirms entries, summarizes, and signs off reconciliations', function (): void {
    Event::fake([ReconciliationEntryConfirmed::class, ReconciliationSignedOff::class]);

    $user = User::factory()->create(['current_team_id' => 1]);
    $this->actingAs($user);

    $createSession = app(CreateReconciliationSession::class);
    $session = $createSession->handle([
        'team_id' => 1,
        'user_id' => $user->getKey(),
        'bank_account_id' => 42,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 100.00,
        'statement_balance' => 250.00,
        'metadata' => ['source' => 'csv'],
    ]);

    expect($session->status)->toBe(ReconciliationStatus::Draft)
        ->and((float) $session->opening_balance)->toBe(100.00)
        ->and((float) $session->statement_balance)->toBe(250.00);

    $emptySummary = app(ReconciliationSummaryQuery::class)->handle($session);
    expect($emptySummary)->toBe([
        'confirmed' => 0,
        'exceptions' => 0,
        'cleared_amount' => 0.0,
        'variance' => -150.0,
    ]);

    $addEntry = app(AddReconciliationEntry::class);
    $entry1 = $addEntry->handle($session, [
        'source_type' => 'feed_tx',
        'source_id' => 'tx_100',
        'kind' => ReconciliationEntryKind::Match->value,
        'amount' => 150.00,
        'currency' => 'usd',
        'confidence' => 0.985,
        'description' => 'Invoice payment',
        'metadata' => ['match_type' => 'exact'],
    ]);

    $entry2 = $addEntry->handle($session, [
        'kind' => ReconciliationEntryKind::Fee->value,
        'amount' => 0.00,
        'currency' => 'usd',
    ]);

    expect($entry1->currency)->toBe('USD')
        ->and($entry1->status)->toBe(ReconciliationEntryStatus::Suggested)
        ->and($entry1->session->is($session))->toBeTrue()
        ->and($entry1->toArray())->not->toHaveKey('metadata')
        ->and($session->entries()->count())->toBe(2)
        ->and($entry2->source_type)->toBeNull();

    $confirm = app(ConfirmReconciliationEntry::class);
    $confirmed = $confirm->handle($entry1);
    expect($confirmed->status)->toBe(ReconciliationEntryStatus::Confirmed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($confirmed->confirmed_by)->toBe($user->getKey());

    Event::assertDispatched(ReconciliationEntryConfirmed::class, fn (ReconciliationEntryConfirmed $e): bool => $e->entry->is($confirmed));

    // Idempotent confirmation
    $reconfirmed = $confirm->handle($confirmed);
    expect($reconfirmed->status)->toBe(ReconciliationEntryStatus::Confirmed);
    Event::assertDispatchedTimes(ReconciliationEntryConfirmed::class, 1);

    $summary = app(ReconciliationSummaryQuery::class)->handle($session);
    expect($summary['confirmed'])->toBe(1)
        ->and($summary['exceptions'])->toBe(0)
        ->and($summary['cleared_amount'])->toBe(150.0)
        ->and($summary['variance'])->toBe(0.0);

    $signOff = app(SignOffReconciliation::class);
    $signedOff = $signOff->handle($session);
    expect($signedOff->status)->toBe(ReconciliationStatus::SignedOff)
        ->and($signedOff->signed_off_at)->not->toBeNull()
        ->and($signedOff->signed_off_by)->toBe($user->getKey());

    Event::assertDispatched(ReconciliationSignedOff::class, fn (ReconciliationSignedOff $e): bool => $e->session->is($signedOff));

    // Idempotent sign-off
    $resigned = $signOff->handle($signedOff);
    expect($resigned->status)->toBe(ReconciliationStatus::SignedOff);
    Event::assertDispatchedTimes(ReconciliationSignedOff::class, 1);

    // Signed-off session blocks adding or confirming entries
    expect(fn () => $addEntry->handle($signedOff, [
        'kind' => ReconciliationEntryKind::Interest->value,
        'amount' => 5,
        'currency' => 'USD',
    ]))->toThrow(InvalidReconciliation::class, 'Signed-off sessions')
        ->and(fn () => $confirm->handle($entry2))->toThrow(InvalidReconciliation::class, 'Signed-off sessions');
});

it('validates reconciliation creation, entry creation, and sign-off constraints', function (): void {
    $create = app(CreateReconciliationSession::class);
    $valid = [
        'bank_account_id' => 99,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'opening_balance' => 0,
        'statement_balance' => 100,
    ];

    expect(fn () => $create->handle([...$valid, 'bank_account_id' => 'abc']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $create->handle([...$valid, 'period_start' => '']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $create->handle([...$valid, 'period_end' => '']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $create->handle([...$valid, 'period_start' => '2026-05-01']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $create->handle([...$valid, 'opening_balance' => 'bad']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $create->handle([...$valid, 'statement_balance' => 'bad']))->toThrow(InvalidReconciliation::class);

    $session = $create->handle($valid);
    expect(fn () => $create->handle($valid))->toThrow(InvalidReconciliation::class, 'already exists');

    $addEntry = app(AddReconciliationEntry::class);
    expect(fn () => $addEntry->handle($session, ['kind' => 'unknown', 'amount' => 10, 'currency' => 'USD']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $addEntry->handle($session, ['kind' => 'match', 'amount' => 'nan', 'currency' => 'USD']))->toThrow(InvalidReconciliation::class)
        ->and(fn () => $addEntry->handle($session, ['kind' => 'match', 'amount' => 10, 'currency' => '']))->toThrow(InvalidReconciliation::class);

    $addEntry->handle($session, [
        'source_type' => 'tx',
        'source_id' => 'dup-1',
        'kind' => ReconciliationEntryKind::Match->value,
        'amount' => 100,
        'currency' => 'USD',
        'status' => ReconciliationEntryStatus::Confirmed,
    ]);

    expect(fn () => $addEntry->handle($session, [
        'source_type' => 'tx',
        'source_id' => 'dup-1',
        'kind' => ReconciliationEntryKind::Match->value,
        'amount' => 100,
        'currency' => 'USD',
    ]))->toThrow(InvalidReconciliation::class, 'already represented');

    // Add an exception entry -> sign off must fail
    $addEntry->handle($session, [
        'kind' => ReconciliationEntryKind::Adjustment->value,
        'amount' => 0,
        'currency' => 'USD',
        'status' => ReconciliationEntryStatus::Exception,
        'exception_reason' => 'Unmatched item',
    ]);

    $signOff = app(SignOffReconciliation::class);
    expect(fn () => $signOff->handle($session))->toThrow(InvalidReconciliation::class, 'no exceptions and zero balance variance');

    // Non-zero variance without exceptions also fails sign-off
    $varianceSession = $create->handle([
        'bank_account_id' => 100,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'opening_balance' => 0,
        'statement_balance' => 50,
    ]);

    expect(fn () => $signOff->handle($varianceSession))->toThrow(InvalidReconciliation::class, 'zero balance variance');
});

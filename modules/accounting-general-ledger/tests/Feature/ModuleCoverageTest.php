<?php

declare(strict_types=1);

use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Enums\NormalBalance;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\GeneralLedger\Actions\CreateAccrual;
use Liberu\Accounting\GeneralLedger\Actions\CreateAllocation;
use Liberu\Accounting\GeneralLedger\Actions\CreateCorrection;
use Liberu\Accounting\GeneralLedger\Actions\CreateJournal;
use Liberu\Accounting\GeneralLedger\Actions\CreatePrepayment;
use Liberu\Accounting\GeneralLedger\Actions\GenerateRecurringJournal;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal;
use Liberu\Accounting\GeneralLedger\Actions\ReverseJournal;
use Liberu\Accounting\GeneralLedger\Actions\SaveRecurringJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Enums\JournalType;
use Liberu\Accounting\GeneralLedger\Events\JournalCreated;
use Liberu\Accounting\GeneralLedger\Events\JournalPosted;
use Liberu\Accounting\GeneralLedger\Events\JournalReversed;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\GeneralLedger\GeneralLedgerServiceProvider;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\GeneralLedger\Models\JournalLine;
use Liberu\Accounting\GeneralLedger\Models\RecurringJournal;
use Liberu\Accounting\GeneralLedger\Queries\AccountBalances;

function makeGlFixture(): array
{
    $entity = LegalEntity::create([
        'name' => 'Acme Corp',
        'registration_number' => 'REG-'.uniqid(),
        'currency_code' => 'USD',
    ]);

    $book = Book::create([
        'legal_entity_id' => $entity->getKey(),
        'name' => 'Primary Ledger',
        'code' => 'MAIN-'.uniqid(),
    ]);

    $cash = Account::create([
        'legal_entity_id' => $entity->getKey(),
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $revenue = Account::create([
        'legal_entity_id' => $entity->getKey(),
        'code' => '4000',
        'name' => 'Revenue',
        'type' => AccountType::Revenue,
        'normal_balance' => NormalBalance::Credit,
    ]);

    return [$entity, $book, $cash, $revenue];
}

it('boots general ledger service provider and covers enums, exception, and events', function (): void {
    $provider = $this->app->register(GeneralLedgerServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(GeneralLedgerServiceProvider::class)
        ->and(array_column(JournalStatus::cases(), 'value'))->toBe(['draft', 'posted', 'reversed'])
        ->and(array_column(JournalType::cases(), 'value'))->toBe(['general', 'recurring', 'correction', 'allocation', 'accrual', 'prepayment', 'reversal'])
        ->and((new InvalidJournal('Journal error'))->getMessage())->toBe('Journal error');

    $entry = new JournalEntry(['entry_number' => 'JE-2026-000001']);
    $reversal = new JournalEntry(['entry_number' => 'JE-2026-000002']);

    $created = new JournalCreated($entry);
    $posted = new JournalPosted($entry, 'controller@example.test');
    $reversed = new JournalReversed($entry, $reversal, 'controller@example.test');

    expect($created->journal)->toBe($entry)
        ->and($posted->journal)->toBe($entry)
        ->and($posted->actor)->toBe('controller@example.test')
        ->and($reversed->journal)->toBe($entry)
        ->and($reversed->reversal)->toBe($reversal)
        ->and($reversed->actor)->toBe('controller@example.test');
});

it('executes journal creation, typed journals, posting, reversal, immutability, and account balances', function (): void {
    [, $book, $cash, $revenue] = makeGlFixture();

    $lines = [
        ['account_id' => $cash->getKey(), 'debit' => 250.00, 'credit' => 0, 'description' => 'Cash received'],
        ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 250.00, 'description' => 'Sales revenue'],
    ];

    $journal = app(CreateJournal::class)->handle([
        'book_id' => $book->getKey(),
        'entry_date' => '2026-01-15',
        'description' => 'January sales',
    ], $lines);

    expect($journal->entry_number)->toBe('JE-2026-000001')
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->journal_type)->toBe(JournalType::General)
        ->and($journal->book->is($book))->toBeTrue()
        ->and($journal->isBalanced())->toBeTrue()
        ->and((float) $journal->totalDebits())->toBe(250.0)
        ->and((float) $journal->totalCredits())->toBe(250.0);

    /** @var JournalLine $firstLine */
    $firstLine = $journal->lines->first();
    expect($firstLine->journalEntry->is($journal))->toBeTrue()
        ->and($firstLine->account->is($cash))->toBeTrue();

    // Draft lifecycle guard: changing status + non-lifecycle field together throws LogicException
    expect(function () use ($journal): void {
        $journal->status = JournalStatus::Posted;
        $journal->description = 'Tampered during post';
        $journal->save();
    })->toThrow(LogicException::class, 'Posting may only change the journal lifecycle fields.');
    $journal->refresh();

    // Post journal
    $posted = app(PostJournal::class)->handle($journal, 'cpa@example.test');
    expect($posted->status)->toBe(JournalStatus::Posted)
        ->and($posted->posted_by)->toBe('cpa@example.test')
        ->and($posted->posted_at)->not->toBeNull();

    // Immutability guards on posted journal and its lines
    expect(fn () => $posted->update(['description' => 'Mutated']))
        ->toThrow(LogicException::class, 'Posted or reversed journals are immutable.')
        ->and(fn () => $posted->delete())
        ->toThrow(LogicException::class, 'Posted or reversed journals cannot be deleted.');

    $postedLine = $posted->lines()->firstOrFail();
    expect(fn () => $postedLine->update(['description' => 'Mutated line']))
        ->toThrow(LogicException::class, 'Lines of posted or reversed journals are immutable.')
        ->and(fn () => $postedLine->delete())
        ->toThrow(LogicException::class, 'Lines of posted or reversed journals cannot be deleted.');

    // AccountBalances query with and without through date
    $balances = app(AccountBalances::class);
    $allBalances = $balances->handle((int) $book->getKey());
    expect($allBalances)->toHaveCount(2);
    $beforeBalances = $balances->handle((int) $book->getKey(), '2026-01-10');
    expect($beforeBalances)->toBeEmpty();
    $throughBalances = $balances->handle((int) $book->getKey(), '2026-01-31');
    expect($throughBalances)->toHaveCount(2);

    // Reverse journal
    $reversal = app(ReverseJournal::class)->handle($posted, ['entry_date' => '2026-01-20'], 'cpa@example.test');
    expect($reversal->status)->toBe(JournalStatus::Posted)
        ->and($reversal->journal_type)->toBe(JournalType::Reversal)
        ->and($reversal->reversalOf->is($posted))->toBeTrue()
        ->and($posted->refresh()->status)->toBe(JournalStatus::Reversed);

    // Typed journal actions
    $baseAttrs = ['book_id' => $book->getKey(), 'entry_date' => '2026-02-01', 'description' => 'Typed'];
    $accrual = app(CreateAccrual::class)->handle($baseAttrs, $lines);
    $allocation = app(CreateAllocation::class)->handle($baseAttrs, $lines);
    $correction = app(CreateCorrection::class)->handle($baseAttrs, $lines);
    $prepayment = app(CreatePrepayment::class)->handle($baseAttrs, $lines);

    expect($accrual->journal_type)->toBe(JournalType::Accrual)
        ->and($allocation->journal_type)->toBe(JournalType::Allocation)
        ->and($correction->journal_type)->toBe(JournalType::Correction)
        ->and($prepayment->journal_type)->toBe(JournalType::Prepayment);

    // Deleting a draft journal and its lines is permitted
    $prepayment->lines()->firstOrFail()->delete();
    $prepayment->delete();
    expect(JournalEntry::query()->find($prepayment->getKey()))->toBeNull();
});

it('executes recurring journal saving, generation across all frequencies, and validations', function (): void {
    [, $book, $cash, $revenue] = makeGlFixture();

    $lines = [
        ['account_id' => $cash->getKey(), 'debit' => 100.00, 'credit' => 0],
        ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 100.00],
    ];

    $save = app(SaveRecurringJournal::class);
    $generate = app(GenerateRecurringJournal::class);

    expect(fn () => $save->handle(['book_id' => $book->getKey(), 'name' => 'Bad Freq', 'frequency' => 'hourly', 'next_run_on' => '2026-01-01', 'lines' => $lines]))
        ->toThrow(InvalidJournal::class, 'Unsupported recurring journal frequency.')
        ->and(fn () => $save->handle(['book_id' => $book->getKey(), 'name' => 'Too Few', 'frequency' => 'monthly', 'next_run_on' => '2026-01-01', 'lines' => [$lines[0]]]))
        ->toThrow(InvalidJournal::class, 'A recurring journal requires at least two lines.');

    $template = $save->handle([
        'book_id' => $book->getKey(),
        'name' => 'Recurring Template',
        'frequency' => 'daily',
        'next_run_on' => '2026-01-01',
        'end_on' => '2028-12-31',
        'is_active' => true,
        'description' => 'Daily template',
        'lines' => $lines,
    ]);

    expect($template)->toBeInstanceOf(RecurringJournal::class)
        ->and($template->book->is($book))->toBeTrue();

    // Daily -> Weekly -> Monthly -> Quarterly -> Yearly
    $generated = $generate->handle($template);
    expect($generated->journal_type)->toBe(JournalType::Recurring)
        ->and($generated->recurringJournals()->count())->toBe(1)
        ->and($template->refresh()->next_run_on->toDateString())->toBe('2026-01-02');

    foreach (['weekly' => '2026-01-09', 'monthly' => '2026-02-09', 'quarterly' => '2026-05-09', 'yearly' => '2027-05-09'] as $freq => $expectedDate) {
        $template = $save->handle(['frequency' => $freq, 'lines' => $lines], $template);
        $generate->handle($template);
        expect($template->refresh()->next_run_on->toDateString())->toBe($expectedDate);
    }

    // Unsupported frequency directly on persisted template
    $template->update(['frequency' => 'hourly']);
    expect(fn () => $generate->handle($template))
        ->toThrow(InvalidJournal::class, 'Unsupported recurring journal frequency.');

    // Past end_on or inactive
    $template->update(['frequency' => 'monthly', 'next_run_on' => '2029-01-01', 'is_active' => true]);
    expect(fn () => $generate->handle($template))
        ->toThrow(InvalidJournal::class, 'Recurring journal is inactive or past its end date.');

    $template->update(['next_run_on' => '2026-01-01', 'is_active' => false]);
    expect(fn () => $generate->handle($template))
        ->toThrow(InvalidJournal::class, 'Recurring journal is inactive or past its end date.');
});

it('enforces validation and posting/reversal preconditions across journal actions', function (): void {
    [, $book, $cash, $revenue] = makeGlFixture();

    $otherEntity = LegalEntity::create([
        'name' => 'Other Corp',
        'registration_number' => 'REG-'.uniqid(),
        'currency_code' => 'USD',
    ]);
    $foreignAccount = Account::create([
        'legal_entity_id' => $otherEntity->getKey(),
        'code' => '1000',
        'name' => 'Foreign Cash',
        'type' => AccountType::Asset,
        'normal_balance' => NormalBalance::Debit,
    ]);
    $inactiveAccount = Account::create([
        'legal_entity_id' => $book->legal_entity_id,
        'code' => '9999',
        'name' => 'Inactive Account',
        'type' => AccountType::Expense,
        'normal_balance' => NormalBalance::Debit,
        'is_active' => false,
    ]);

    $create = app(CreateJournal::class);
    $validLines = [
        ['account_id' => $cash->getKey(), 'debit' => 50, 'credit' => 0],
        ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 50],
    ];

    expect(fn () => $create->handle(['entry_date' => '2026-01-01'], $validLines))
        ->toThrow(InvalidJournal::class, 'A journal requires a book and at least two lines.')
        ->and(fn () => $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [$validLines[0]]))
        ->toThrow(InvalidJournal::class, 'A journal requires a book and at least two lines.')
        ->and(fn () => $create->handle(['book_id' => 999999, 'entry_date' => '2026-01-01'], $validLines))
        ->toThrow(InvalidJournal::class, 'The journal book does not exist.')
        ->and(fn () => $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [
            ['debit' => 50, 'credit' => 0],
            $validLines[1],
        ]))
        ->toThrow(InvalidJournal::class, 'Every journal line must reference an account in the book legal entity.')
        ->and(fn () => $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [
            ['account_id' => $foreignAccount->getKey(), 'debit' => 50, 'credit' => 0],
            ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 50],
        ]))
        ->toThrow(InvalidJournal::class, 'Every journal line must reference an account in the book legal entity.')
        ->and(fn () => $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [
            ['account_id' => $cash->getKey(), 'debit' => 10, 'credit' => 10],
            ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 10],
        ]))
        ->toThrow(InvalidJournal::class, 'Each line must contain either a positive debit or credit.')
        ->and(fn () => $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [
            ['account_id' => $cash->getKey(), 'debit' => 100, 'credit' => 0],
            ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 50],
        ]))
        ->toThrow(InvalidJournal::class, 'Journal debits and credits must balance.');

    // Unbalanced draft journal cannot be posted
    $unbalanced = JournalEntry::create([
        'book_id' => $book->getKey(),
        'entry_date' => '2026-01-01',
        'journal_type' => JournalType::General->value,
        'status' => JournalStatus::Draft->value,
    ]);
    $unbalanced->lines()->create(['account_id' => $cash->getKey(), 'debit' => 10, 'credit' => 0]);

    $post = app(PostJournal::class);
    expect(fn () => $post->handle($unbalanced))
        ->toThrow(InvalidJournal::class, 'Journal must be balanced before posting.');

    // Inactive account cannot be posted
    $withInactive = $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], [
        ['account_id' => $inactiveAccount->getKey(), 'debit' => 25, 'credit' => 0],
        ['account_id' => $revenue->getKey(), 'debit' => 0, 'credit' => 25],
    ]);
    expect(fn () => $post->handle($withInactive))
        ->toThrow(InvalidJournal::class, 'Every journal account must be active and permit manual entries.');

    // Cannot reverse draft, cannot post already posted
    $draft = $create->handle(['book_id' => $book->getKey(), 'entry_date' => '2026-01-01'], $validLines);
    expect(fn () => app(ReverseJournal::class)->handle($draft))
        ->toThrow(InvalidJournal::class, 'Only posted journals may be reversed.');

    $posted = $post->handle($draft);
    expect(fn () => $post->handle($posted))
        ->toThrow(InvalidJournal::class, 'Only draft journals may be posted.');
});

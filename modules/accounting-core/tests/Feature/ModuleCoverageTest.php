<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\Core\AccountingCoreServiceProvider;
use Liberu\Accounting\Core\Actions\AllocateNumber;
use Liberu\Accounting\Core\Actions\CreateLegalEntity;
use Liberu\Accounting\Core\Actions\SaveAccountingSetting;
use Liberu\Accounting\Core\Actions\SaveBook;
use Liberu\Accounting\Core\Actions\SaveFiscalCalendar;
use Liberu\Accounting\Core\Actions\SaveNumberingSequence;
use Liberu\Accounting\Core\Actions\UpdateLegalEntity;
use Liberu\Accounting\Core\Enums\AccountingBasis;
use Liberu\Accounting\Core\Events\AccountingSettingSaved;
use Liberu\Accounting\Core\Events\BookCreated;
use Liberu\Accounting\Core\Events\FiscalCalendarCreated;
use Liberu\Accounting\Core\Events\LegalEntityCreated;
use Liberu\Accounting\Core\Events\NumberingSequenceCreated;
use Liberu\Accounting\Core\Exceptions\InvalidFiscalCalendar;
use Liberu\Accounting\Core\Models\AccountingDefault;
use Liberu\Accounting\Core\Models\AccountingPolicy;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\FiscalCalendar;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Core\Models\NumberingSequence;
use Liberu\Accounting\Core\ValueObjects\CurrencyCode;

it('boots accounting core service provider and covers enum and currency code value object', function (): void {
    $provider = $this->app->register(AccountingCoreServiceProvider::class, force: true);
    $provider->boot();

    $gbp = new CurrencyCode('GBP');
    $usd = new CurrencyCode('USD');

    expect($provider)->toBeInstanceOf(AccountingCoreServiceProvider::class)
        ->and(array_column(AccountingBasis::cases(), 'value'))->toBe(['accrual', 'cash'])
        ->and($gbp->value)->toBe('GBP')
        ->and($gbp->equals(new CurrencyCode('GBP')))->toBeTrue()
        ->and($gbp->equals($usd))->toBeFalse();

    expect(fn () => new CurrencyCode('gbp'))
        ->toThrow(InvalidArgumentException::class, 'Currency codes must be three uppercase letters.');
    expect(fn () => new CurrencyCode('US'))
        ->toThrow(InvalidArgumentException::class);
});

it('creates and updates legal entities, books, numbering sequences, settings, and covers all model relations', function (): void {
    Event::fake([
        LegalEntityCreated::class,
        BookCreated::class,
        NumberingSequenceCreated::class,
        AccountingSettingSaved::class,
    ]);

    $entity = app(CreateLegalEntity::class)->handle([
        'name' => 'Acme Holdings Ltd',
        'registration_number' => 'REG-001',
        'currency_code' => 'GBP',
        'accounting_basis' => AccountingBasis::Accrual->value,
    ]);

    expect($entity)->toBeInstanceOf(LegalEntity::class)
        ->and($entity->is_active)->toBeTrue()
        ->and($entity->name)->toBe('Acme Holdings Ltd');

    Event::assertDispatched(LegalEntityCreated::class, fn (LegalEntityCreated $e): bool => $e->legalEntity->is($entity));

    $updatedEntity = app(UpdateLegalEntity::class)->handle($entity, [
        'name' => 'Acme Global Holdings Ltd',
        'accounting_basis' => AccountingBasis::Cash->value,
    ]);

    expect($updatedEntity->name)->toBe('Acme Global Holdings Ltd')
        ->and($updatedEntity->accounting_basis)->toBe('cash');

    $saveBook = app(SaveBook::class);
    $book = $saveBook->handle(null, [
        'legal_entity_id' => $entity->id,
        'name' => 'General Ledger',
        'code' => 'GL-MAIN',
        'accounting_basis' => AccountingBasis::Accrual->value,
        'is_active' => true,
    ]);

    expect($book)->toBeInstanceOf(Book::class)
        ->and($book->code)->toBe('GL-MAIN')
        ->and($book->legalEntity->is($entity))->toBeTrue()
        ->and($entity->books()->count())->toBe(1);

    $updatedBook = $saveBook->handle($book, ['name' => 'Primary General Ledger']);
    expect($updatedBook->name)->toBe('Primary General Ledger');

    $saveSeq = app(SaveNumberingSequence::class);
    $sequence = $saveSeq->handle(null, [
        'book_id' => $book->id,
        'key' => 'journal_entry',
        'prefix' => 'JE-',
        'next_number' => 1,
        'padding' => 5,
    ]);

    expect($sequence)->toBeInstanceOf(NumberingSequence::class)
        ->and($sequence->book->is($book))->toBeTrue()
        ->and($book->numberingSequences()->count())->toBe(1);

    $updatedSequence = $saveSeq->handle($sequence, ['prefix' => 'JRN-']);
    expect($updatedSequence->prefix)->toBe('JRN-');

    $first = app(AllocateNumber::class)->handle($updatedSequence);
    $second = app(AllocateNumber::class)->handle($updatedSequence);

    expect($first)->toBe('JRN-00001')
        ->and($second)->toBe('JRN-00002')
        ->and($updatedSequence->refresh()->next_number)->toBe(3);

    $saveSetting = app(SaveAccountingSetting::class);
    $default = $saveSetting->handle(AccountingDefault::class, null, [
        'book_id' => $book->id,
        'key' => 'retained_earnings',
        'value' => ['account_code' => '3200'],
    ]);
    $defaultUpdated = $saveSetting->handle(AccountingDefault::class, $default, [
        'value' => ['account_code' => '3210'],
    ]);

    $policy = $saveSetting->handle(AccountingPolicy::class, null, [
        'book_id' => $book->id,
        'key' => 'revenue_recognition',
        'value' => ['method' => 'milestone'],
    ]);

    expect($defaultUpdated)->toBeInstanceOf(AccountingDefault::class)
        ->and($defaultUpdated->value)->toBe(['account_code' => '3210'])
        ->and($defaultUpdated->book->is($book))->toBeTrue()
        ->and($policy)->toBeInstanceOf(AccountingPolicy::class)
        ->and($policy->book->is($book))->toBeTrue()
        ->and($book->defaults()->count())->toBe(1)
        ->and($book->policies()->count())->toBe(1);

    // Explicitly instantiate events to guarantee constructor coverage
    expect((new BookCreated($book))->book->is($book))->toBeTrue()
        ->and((new NumberingSequenceCreated($sequence))->sequence->is($sequence))->toBeTrue()
        ->and((new AccountingSettingSaved($policy))->setting->is($policy))->toBeTrue();
});

it('saves, updates, and validates fiscal calendars including overlap and invalid date ranges', function (): void {
    Event::fake([FiscalCalendarCreated::class]);

    $entity = app(CreateLegalEntity::class)->handle([
        'name' => 'Calendar Entity',
        'registration_number' => 'REG-CAL-01',
        'currency_code' => 'EUR',
    ]);
    $book = app(SaveBook::class)->handle(null, [
        'legal_entity_id' => $entity->id,
        'name' => 'Statutory Book',
        'code' => 'STAT',
    ]);

    $saveCalendar = app(SaveFiscalCalendar::class);

    expect(fn () => $saveCalendar->handle(null, [
        'book_id' => $book->id,
        'starts_on' => '2026-12-31',
        'ends_on' => '2026-01-01',
    ]))->toThrow(InvalidFiscalCalendar::class, 'Fiscal calendar start must not be after its end.');

    $cal2026 = $saveCalendar->handle(null, [
        'book_id' => $book->id,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'is_closed' => false,
    ]);

    expect($cal2026)->toBeInstanceOf(FiscalCalendar::class)
        ->and($cal2026->book->is($book))->toBeTrue()
        ->and($book->fiscalCalendars()->count())->toBe(1)
        ->and((new FiscalCalendarCreated($cal2026))->calendar->is($cal2026))->toBeTrue();

    // Updating the same calendar should exclude itself from overlap check
    $cal2026Updated = $saveCalendar->handle($cal2026, [
        'book_id' => $book->id,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'is_closed' => true,
    ]);
    expect($cal2026Updated->is_closed)->toBeTrue();

    // Overlapping calendar must fail
    expect(fn () => $saveCalendar->handle(null, [
        'book_id' => $book->id,
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
    ]))->toThrow(InvalidFiscalCalendar::class, 'Fiscal calendar periods may not overlap within a book.');

    // Non-overlapping calendar succeeds
    $cal2027 = $saveCalendar->handle(null, [
        'book_id' => $book->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
    ]);
    expect($cal2027->id)->not->toBe($cal2026->id);
});

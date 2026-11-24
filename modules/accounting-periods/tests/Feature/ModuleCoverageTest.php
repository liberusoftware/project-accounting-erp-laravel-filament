<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Periods\AccountingPeriodsServiceProvider;
use Liberu\Accounting\Periods\Actions\CreatePeriod;
use Liberu\Accounting\Periods\Actions\LockPeriod;
use Liberu\Accounting\Periods\Actions\TransitionPeriod;
use Liberu\Accounting\Periods\Actions\UnlockPeriod;
use Liberu\Accounting\Periods\Enums\PeriodState;
use Liberu\Accounting\Periods\Events\PeriodLockChanged;
use Liberu\Accounting\Periods\Events\PeriodStateChanged;
use Liberu\Accounting\Periods\Exceptions\InvalidPeriodTransition;
use Liberu\Accounting\Periods\Models\AccountingPeriod;

function makeAccountingBook(string $code = 'MAIN'): Book
{
    $entity = LegalEntity::query()->create([
        'name' => 'Test Entity '.$code,
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);

    return Book::query()->create([
        'legal_entity_id' => $entity->id,
        'name' => 'Book '.$code,
        'code' => $code,
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
}

it('registers and boots the accounting periods service provider', function (): void {
    $provider = $this->app->register(AccountingPeriodsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(AccountingPeriodsServiceProvider::class);
});

it('exposes all period state enum cases', function (): void {
    expect(array_map(fn (PeriodState $s): string => $s->value, PeriodState::cases()))
        ->toBe(['open', 'soft_closed', 'hard_closed']);
});

it('validates dates and overlaps when creating accounting periods and checks posting allowance', function (): void {
    $book = makeAccountingBook('CR');
    $create = app(CreatePeriod::class);

    expect(fn () => $create->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-01-01',
    ]))->toThrow(InvalidArgumentException::class, 'Period start must not be after its end.');

    expect(fn () => $create->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'posting_ends_on' => '2026-02-05',
    ]))->toThrow(InvalidArgumentException::class, 'Posting end must not be after the period end.');

    $period = $create->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'posting_ends_on' => '2026-01-25',
    ]);

    expect($period)->toBeInstanceOf(AccountingPeriod::class)
        ->and($period->state)->toBe(PeriodState::Open)
        ->and($period->book->is($book))->toBeTrue()
        ->and($period->isPostingAllowed('2026-01-15'))->toBeTrue()
        ->and($period->isPostingAllowed(new DateTimeImmutable('2026-01-25')))->toBeTrue()
        ->and($period->isPostingAllowed('2026-01-26'))->toBeFalse()
        ->and($period->isPostingAllowed('2025-12-31'))->toBeFalse();

    expect(fn () => $create->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-01-15',
        'ends_on' => '2026-02-15',
    ]))->toThrow(InvalidArgumentException::class, 'Accounting periods may not overlap within a book.');

    // Period without posting_ends_on uses ends_on
    $feb = $create->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-02-28',
    ]);
    expect($feb->isPostingAllowed('2026-02-28'))->toBeTrue();
});

it('transitions, locks, and unlocks accounting periods with events and evidence', function (): void {
    Event::fake([PeriodStateChanged::class, PeriodLockChanged::class]);

    $book = makeAccountingBook('TR');
    $period = app(CreatePeriod::class)->handle([
        'book_id' => $book->id,
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
    ]);

    $transition = app(TransitionPeriod::class);
    $lock = app(LockPeriod::class);
    $unlock = app(UnlockPeriod::class);

    // Invalid direct jump Open -> HardClosed
    expect(fn () => $transition->handle($period, PeriodState::HardClosed))
        ->toThrow(InvalidPeriodTransition::class, 'Cannot transition period from open to hard_closed.');

    // Cannot lock before HardClosed
    expect(fn () => $lock->handle($period, 'auditor@example.com'))
        ->toThrow(InvalidPeriodTransition::class, 'Only hard-closed periods may be locked.');

    $softClosed = $transition->handle($period, PeriodState::SoftClosed, 'cfo@example.com');
    expect($softClosed->state)->toBe(PeriodState::SoftClosed)
        ->and($softClosed->isPostingAllowed('2026-03-15'))->toBeFalse();

    // Reopening requires a reason
    expect(fn () => $transition->handle($softClosed, PeriodState::Open, 'cfo@example.com', ''))
        ->toThrow(InvalidPeriodTransition::class, 'Reopening a period requires a reason.');

    $reopened = $transition->handle($softClosed, PeriodState::Open, 'cfo@example.com', 'Late accrual');
    expect($reopened->state)->toBe(PeriodState::Open)
        ->and($reopened->reopened_by)->toBe('cfo@example.com')
        ->and($reopened->reopen_reason)->toBe('Late accrual');

    $softAgain = $transition->handle($reopened, PeriodState::SoftClosed);
    $hardClosed = $transition->handle($softAgain, PeriodState::HardClosed);
    expect($hardClosed->state)->toBe(PeriodState::HardClosed);

    // HardClosed cannot transition
    expect(fn () => $transition->handle($hardClosed, PeriodState::SoftClosed))
        ->toThrow(InvalidPeriodTransition::class);

    // Lock and idempotent re-lock
    $locked = $lock->handle($hardClosed, 'controller@example.com');
    expect($locked->locked_by)->toBe('controller@example.com')
        ->and($locked->locked_at)->not->toBeNull();

    $relocked = $lock->handle($locked, 'other@example.com');
    expect($relocked->locked_by)->toBe('controller@example.com');

    // Unlock validation and idempotency
    expect(fn () => $unlock->handle($locked, 'controller@example.com', ' '))
        ->toThrow(InvalidPeriodTransition::class, 'Unlocking a period requires a reason.');

    $unlocked = $unlock->handle($locked, 'controller@example.com', 'Audit adjustment');
    expect($unlocked->locked_by)->toBeNull()
        ->and($unlocked->locked_at)->toBeNull()
        ->and($unlocked->evidence)->toHaveCount(1)
        ->and($unlocked->evidence[0]['reason'])->toBe('Audit adjustment');

    // Already unlocked returns as-is without extra evidence
    $stillUnlocked = $unlock->handle($unlocked, 'controller@example.com', 'Second check');
    expect($stillUnlocked->evidence)->toHaveCount(1);

    Event::assertDispatched(PeriodStateChanged::class, 4);
    Event::assertDispatched(PeriodLockChanged::class, 2);
});

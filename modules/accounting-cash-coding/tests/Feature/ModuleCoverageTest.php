<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CashCoding\Actions\CreateCashCodingBatch;
use Liberu\Accounting\CashCoding\Actions\TransitionCashCodingBatch;
use Liberu\Accounting\CashCoding\CashCodingServiceProvider;
use Liberu\Accounting\CashCoding\Enums\CashCodingStatus;
use Liberu\Accounting\CashCoding\Exceptions\InvalidCashCoding;
use Liberu\Accounting\CashCoding\Models\CashCodingBatch;
use Liberu\Accounting\CashCoding\Queries\CashCodingQuery;

it('boots the cash coding service provider and schema', function (): void {
    $provider = app()->getProvider(CashCodingServiceProvider::class);
    expect($provider)->toBeInstanceOf(CashCodingServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_cash_coding_batches'))->toBeTrue();
});

it('exposes cash coding status enum cases and exception', function (): void {
    expect(array_map(static fn (CashCodingStatus $s): string => $s->value, CashCodingStatus::cases()))
        ->toBe(['draft', 'in_review', 'posted', 'undone']);

    $exception = new InvalidCashCoding('Bad batch');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Bad batch');
});

it('creates a cash coding batch, casts attributes, and hides metadata', function (): void {
    $batch = (new CreateCashCodingBatch())->handle([
        'team_id' => 5,
        'reference' => '  CCB-2026-01  ',
        'currency' => 'gbp',
        'created_by' => 42,
        'metadata' => ['source' => 'bank-feed'],
        'lines' => [
            10 => ['source_reference' => 'TX-1', 'amount' => 125.50, 'account_id' => 101],
            20 => ['source_reference' => 'TX-2', 'amount' => 74.50, 'currency' => 'GBP', 'account_id' => 102],
        ],
    ]);

    expect($batch)->toBeInstanceOf(CashCodingBatch::class)
        ->and($batch->reference)->toBe('CCB-2026-01')
        ->and($batch->currency)->toBe('GBP')
        ->and($batch->status)->toBe(CashCodingStatus::Draft)
        ->and((float) $batch->total_amount)->toEqualWithDelta(200.0, 0.0001)
        ->and($batch->lines)->toHaveCount(2)
        ->and(array_keys($batch->lines))->toBe([0, 1])
        ->and($batch->toArray())->not->toHaveKey('metadata')
        ->and($batch->metadata)->toBe(['source' => 'bank-feed']);
});

it('validates batch header, line fields, and duplicate references on creation', function (): void {
    $creator = new CreateCashCodingBatch();

    expect(fn () => $creator->handle(['team_id' => 0, 'reference' => 'A', 'currency' => 'USD', 'lines' => [['source_reference' => 'X', 'amount' => 10, 'account_id' => 1]]]))
        ->toThrow(InvalidCashCoding::class, 'between 1 and 500 lines');

    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => '   ', 'currency' => 'USD', 'lines' => [['source_reference' => 'X', 'amount' => 10, 'account_id' => 1]]]))
        ->toThrow(InvalidCashCoding::class);

    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-1', 'currency' => 'USD', 'lines' => []]))
        ->toThrow(InvalidCashCoding::class);

    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-1', 'currency' => 'USD', 'lines' => 'invalid']))
        ->toThrow(InvalidCashCoding::class);

    $tooManyLines = array_fill(0, 501, ['source_reference' => 'X', 'amount' => 1, 'account_id' => 1]);
    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-1', 'currency' => 'USD', 'lines' => $tooManyLines]))
        ->toThrow(InvalidCashCoding::class);

    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-1', 'currency' => 'US', 'lines' => [['source_reference' => 'X', 'amount' => 10, 'account_id' => 1]]]))
        ->toThrow(InvalidCashCoding::class);

    // Invalid line cases
    foreach ([
        ['not-an-array'],
        [['source_reference' => '', 'amount' => 10, 'account_id' => 1]],
        [['source_reference' => 'TX', 'amount' => 'abc', 'account_id' => 1]],
        [['source_reference' => 'TX', 'amount' => 10, 'currency' => 'EUR', 'account_id' => 1]],
        [['source_reference' => 'TX', 'amount' => 10, 'account_id' => null]],
    ] as $badLines) {
        expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-BAD', 'currency' => 'USD', 'lines' => $badLines]))
            ->toThrow(InvalidCashCoding::class, 'Every coding line requires');
    }

    // Duplicate reference per team
    $creator->handle(['team_id' => 1, 'reference' => 'REF-DUP', 'currency' => 'USD', 'lines' => [['source_reference' => 'TX', 'amount' => 10, 'account_id' => 1]]]);

    expect(fn () => $creator->handle(['team_id' => 1, 'reference' => 'REF-DUP', 'currency' => 'USD', 'lines' => [['source_reference' => 'TX2', 'amount' => 20, 'account_id' => 2]]]))
        ->toThrow(InvalidCashCoding::class, 'already exists');
});

it('transitions a cash coding batch through review, post, and undo with guards', function (): void {
    $creator = new CreateCashCodingBatch();
    $transition = new TransitionCashCodingBatch();

    $batch = $creator->handle([
        'team_id' => 2,
        'reference' => 'CCB-FLOW',
        'currency' => 'EUR',
        'lines' => [['source_reference' => 'TX-1', 'amount' => 50, 'account_id' => 10]],
    ]);

    // Cannot post or undo directly from draft
    expect(fn () => $transition->post($batch, 99))
        ->toThrow(InvalidCashCoding::class, 'Only reviewed coding batches can be posted.');
    expect(fn () => $transition->undo($batch, 'mistake'))
        ->toThrow(InvalidCashCoding::class, 'Only posted batches can be undone');

    // Draft -> InReview
    $reviewed = $transition->review($batch, 15);
    expect($reviewed->status)->toBe(CashCodingStatus::InReview)
        ->and($reviewed->reviewed_by)->toBe(15);

    // Cannot review again
    expect(fn () => $transition->review($reviewed, 16))
        ->toThrow(InvalidCashCoding::class, 'Only draft coding batches can enter review.');

    // InReview -> Posted
    $posted = $transition->post($reviewed, 25);
    expect($posted->status)->toBe(CashCodingStatus::Posted)
        ->and($posted->posted_by)->toBe(25)
        ->and($posted->posted_at)->not->toBeNull();

    // Posting an already posted batch is idempotent
    $same = $transition->post($posted, 99);
    expect($same->posted_by)->toBe(25);

    // Blank undo reason fails
    expect(fn () => $transition->undo($posted, '   '))
        ->toThrow(InvalidCashCoding::class, 'undo reason is required');

    // Posted -> Undone
    $undone = $transition->undo($posted, '  Wrong bank feed  ');
    expect($undone->status)->toBe(CashCodingStatus::Undone)
        ->and($undone->undo_reason)->toBe('Wrong bank feed')
        ->and($undone->undone_at)->not->toBeNull();
});

it('queries cash coding batches by team', function (): void {
    $query = new CashCodingQuery();
    expect($query->forTeam(99)->get())->toBeEmpty();

    $creator = new CreateCashCodingBatch();
    $first = $creator->handle([
        'team_id' => 3,
        'reference' => 'B-1',
        'currency' => 'USD',
        'lines' => [['source_reference' => 'T1', 'amount' => 10, 'account_id' => 1]],
    ]);
    $second = $creator->handle([
        'team_id' => 3,
        'reference' => 'B-2',
        'currency' => 'USD',
        'lines' => [['source_reference' => 'T2', 'amount' => 20, 'account_id' => 2]],
    ]);
    $creator->handle([
        'team_id' => 4,
        'reference' => 'B-3',
        'currency' => 'USD',
        'lines' => [['source_reference' => 'T3', 'amount' => 30, 'account_id' => 3]],
    ]);

    $results = $query->forTeam(3)->get();
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toContain($first->id, $second->id);
});

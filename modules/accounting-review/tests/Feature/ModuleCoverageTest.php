<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Review\Actions\CreateReviewItem;
use Liberu\Accounting\Review\Actions\ResolveReviewItem;
use Liberu\Accounting\Review\Actions\SignOffReviewItem;
use Liberu\Accounting\Review\Enums\ReviewStatus;
use Liberu\Accounting\Review\Exceptions\InvalidReviewItem;
use Liberu\Accounting\Review\Models\ReviewItem;
use Liberu\Accounting\Review\Queries\ReviewItemQuery;
use Liberu\Accounting\Review\ReviewServiceProvider;

it('registers the review service provider and runs migrations', function (): void {
    expect(app()->getProvider(ReviewServiceProvider::class))
        ->toBeInstanceOf(ReviewServiceProvider::class);

    expect(Schema::hasTable('accounting_review_items'))->toBeTrue();
    expect(Schema::hasColumns('accounting_review_items', [
        'id', 'team_id', 'item_type', 'source_type', 'source_id', 'severity', 'status', 'title',
        'details', 'resolution', 'resolved_by', 'resolved_at', 'signoff', 'signed_off_by', 'signed_off_at', 'due_at',
    ]))->toBeTrue();
});

it('exposes all review status enum cases', function (): void {
    expect(array_column(ReviewStatus::cases(), 'value'))
        ->toBe(['open', 'in_progress', 'resolved', 'signed_off']);
});

it('creates review items and validates required attributes', function (): void {
    $create = new CreateReviewItem();

    $item = $create->handle([
        'team_id' => 21,
        'item_type' => 'unreconciled_transaction',
        'source_type' => 'bank_transaction',
        'source_id' => 'BT-100',
        'severity' => 'high',
        'title' => 'Large unmatched wire transfer',
        'details' => ['amount' => 15000],
        'due_at' => '2026-09-15 17:00:00',
    ]);

    expect($item)->toBeInstanceOf(ReviewItem::class)
        ->and($item->status)->toBe(ReviewStatus::Open)
        ->and($item->severity)->toBe('high')
        ->and($item->details)->toBe(['amount' => 15000])
        ->and($item->due_at)->not->toBeNull();

    $defaultSeverity = $create->handle([
        'team_id' => 21,
        'item_type' => 'exception',
        'title' => 'Default medium severity item',
    ]);
    expect($defaultSeverity->severity)->toBe('medium');

    foreach (['team_id', 'item_type', 'title'] as $missing) {
        $payload = ['team_id' => 21, 'item_type' => 'exception', 'title' => 'Test'];
        unset($payload[$missing]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidReviewItem::class, "{$missing} is required.");
    }
});

it('resolves and signs off review items across valid and invalid state transitions', function (): void {
    $create = new CreateReviewItem();
    $resolve = new ResolveReviewItem();
    $signOff = new SignOffReviewItem();

    $item = $create->handle([
        'team_id' => 22,
        'item_type' => 'unusual_balance',
        'title' => 'Negative petty cash balance',
    ]);

    // Cannot sign off before resolving
    expect(fn () => $signOff->handle($item, 99, 'Looks fine'))
        ->toThrow(InvalidReviewItem::class, 'Only resolved review items can be signed off.');

    // Cannot resolve without summary
    expect(fn () => $resolve->handle($item, 50, ['summary' => '']))
        ->toThrow(InvalidReviewItem::class, 'Only open review items with a resolution summary can be resolved.');

    $resolved = $resolve->handle($item, 50, ['summary' => 'Reclassified cash deposit.', 'reference' => 'JE-404']);
    expect($resolved->status)->toBe(ReviewStatus::Resolved)
        ->and($resolved->resolved_by)->toBe(50)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and($resolved->resolution)->toBe(['summary' => 'Reclassified cash deposit.', 'reference' => 'JE-404', 'resolved_by' => 50]);

    // Cannot resolve again once resolved
    expect(fn () => $resolve->handle($resolved, 50, ['summary' => 'Retry']))
        ->toThrow(InvalidReviewItem::class);

    // Cannot sign off with blank attestation
    expect(fn () => $signOff->handle($resolved, 99, ''))
        ->toThrow(InvalidReviewItem::class, 'Only resolved review items can be signed off.');

    $signedOff = $signOff->handle($resolved, 99, 'Reviewed and approved for month-end close.');
    expect($signedOff->status)->toBe(ReviewStatus::SignedOff)
        ->and($signedOff->signed_off_by)->toBe(99)
        ->and($signedOff->signed_off_at)->not->toBeNull()
        ->and($signedOff->signoff)->toBe([
            'attestation' => 'Reviewed and approved for month-end close.',
            'signed_off_by' => 99,
        ]);

    // InProgress item can also be resolved
    $inProgress = $create->handle([
        'team_id' => 22,
        'item_type' => 'query',
        'title' => 'Clarify vendor invoice',
    ]);
    $inProgress->update(['status' => ReviewStatus::InProgress]);

    $resolvedInProgress = $resolve->handle($inProgress->fresh(), 51, ['summary' => 'Vendor confirmed credit memo.']);
    expect($resolvedInProgress->status)->toBe(ReviewStatus::Resolved);
});

it('paginates review items with team, status filter, and perPage bounds', function (): void {
    $create = new CreateReviewItem();
    $resolve = new ResolveReviewItem();
    $query = new ReviewItemQuery();

    expect($query->paginate(999)->total())->toBe(0);

    $open1 = $create->handle(['team_id' => 30, 'item_type' => 'a', 'title' => 'Open 1']);
    $open2 = $create->handle(['team_id' => 30, 'item_type' => 'b', 'title' => 'Open 2']);
    $resolved = $create->handle(['team_id' => 30, 'item_type' => 'c', 'title' => 'Resolved 1']);
    $resolve->handle($resolved, 1, ['summary' => 'Done']);
    $create->handle(['team_id' => 31, 'item_type' => 'd', 'title' => 'Other Team']);

    $allForTeam = $query->paginate(30);
    expect($allForTeam->total())->toBe(3);

    $openOnly = $query->paginate(30, ReviewStatus::Open->value);
    expect($openOnly->total())->toBe(2)
        ->and($openOnly->items()[0]->id)->toBeGreaterThanOrEqual($open1->id);

    // Lower clamp (min 1)
    $clampedLow = $query->paginate(30, null, 0);
    expect($clampedLow->perPage())->toBe(1)
        ->and($clampedLow->items())->toHaveCount(1);

    // Upper clamp (max 100)
    $clampedHigh = $query->paginate(30, null, 500);
    expect($clampedHigh->perPage())->toBe(100)
        ->and($clampedHigh->total())->toBe(3);

    expect($open2->id)->toBeGreaterThan($open1->id);
});

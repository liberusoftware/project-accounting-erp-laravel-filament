<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CodingSuggestions\Actions\ApplySuggestionFeedback;
use Liberu\Accounting\CodingSuggestions\Actions\CreateCodingSuggestion;
use Liberu\Accounting\CodingSuggestions\Actions\ReviewCodingSuggestion;
use Liberu\Accounting\CodingSuggestions\Actions\SetSuggestionPolicy;
use Liberu\Accounting\CodingSuggestions\CodingSuggestionsServiceProvider;
use Liberu\Accounting\CodingSuggestions\Enums\CodingSuggestionStatus;
use Liberu\Accounting\CodingSuggestions\Exceptions\InvalidCodingSuggestion;
use Liberu\Accounting\CodingSuggestions\Models\CodingSuggestion;
use Liberu\Accounting\CodingSuggestions\Queries\CodingSuggestionQuery;

it('registers the coding suggestions service provider and runs migrations', function (): void {
    expect(app()->getProvider(CodingSuggestionsServiceProvider::class))
        ->toBeInstanceOf(CodingSuggestionsServiceProvider::class);

    expect(Schema::hasTable('accounting_coding_suggestions'))->toBeTrue();
    expect(Schema::hasColumns('accounting_coding_suggestions', [
        'id',
        'team_id',
        'source_ref',
        'target_type',
        'target_ref',
        'confidence',
        'explanation',
        'status',
        'feedback',
        'policy',
        'review',
    ]))->toBeTrue();
});

it('exposes all coding suggestion status enum cases', function (): void {
    expect(array_column(CodingSuggestionStatus::cases(), 'value'))
        ->toBe(['pending', 'accepted', 'rejected', 'reviewed']);
});

it('creates coding suggestions and validates required fields and confidence bounds', function (): void {
    $action = new CreateCodingSuggestion();

    $suggestion = $action->handle([
        'team_id' => 10,
        'source_ref' => 'TX-1001',
        'target_type' => 'account',
        'target_ref' => '6100-SUPPLIES',
        'confidence' => 0.925,
        'explanation' => 'Matched office supplies vendor history.',
    ]);

    expect($suggestion)->toBeInstanceOf(CodingSuggestion::class)
        ->and($suggestion->status)->toBe(CodingSuggestionStatus::Pending)
        ->and($suggestion->confidence)->toBe('0.925000')
        ->and($suggestion->team_id)->toBe(10);

    $zeroConfidence = $action->handle([
        'team_id' => 10,
        'source_ref' => 'TX-1002',
        'target_type' => 'account',
        'target_ref' => '6100-SUPPLIES',
        'confidence' => 0,
        'explanation' => 'Zero confidence boundary.',
    ]);

    expect($zeroConfidence->confidence)->toBe('0.000000');

    $maxConfidence = $action->handle([
        'team_id' => 10,
        'source_ref' => 'TX-1003',
        'target_type' => 'account',
        'target_ref' => '6100-SUPPLIES',
        'confidence' => 1,
        'explanation' => 'Max confidence boundary.',
    ]);

    expect($maxConfidence->confidence)->toBe('1.000000');

    foreach (['team_id', 'source_ref', 'target_type', 'target_ref', 'confidence', 'explanation'] as $missingField) {
        $payload = [
            'team_id' => 10,
            'source_ref' => 'TX-ERR',
            'target_type' => 'account',
            'target_ref' => '6100',
            'confidence' => 0.5,
            'explanation' => 'Reason',
        ];
        unset($payload[$missingField]);

        expect(fn () => $action->handle($payload))
            ->toThrow(InvalidCodingSuggestion::class, "{$missingField} is required.");
    }

    expect(fn () => $action->handle([
        'team_id' => 10,
        'source_ref' => 'TX-LOW',
        'target_type' => 'account',
        'target_ref' => '6100',
        'confidence' => -0.01,
        'explanation' => 'Negative confidence.',
    ]))->toThrow(InvalidCodingSuggestion::class, 'Confidence must be between zero and one.');

    expect(fn () => $action->handle([
        'team_id' => 10,
        'source_ref' => 'TX-HIGH',
        'target_type' => 'account',
        'target_ref' => '6100',
        'confidence' => 1.01,
        'explanation' => 'Too high confidence.',
    ]))->toThrow(InvalidCodingSuggestion::class, 'Confidence must be between zero and one.');
});

it('applies feedback, sets policy, and reviews suggestions across valid and invalid transitions', function (): void {
    $create = new CreateCodingSuggestion();
    $applyFeedback = new ApplySuggestionFeedback();
    $review = new ReviewCodingSuggestion();
    $setPolicy = new SetSuggestionPolicy();

    $acceptedSuggestion = $create->handle([
        'team_id' => 20,
        'source_ref' => 'TX-2001',
        'target_type' => 'tax_code',
        'target_ref' => 'VAT-20',
        'confidence' => 0.88,
        'explanation' => 'Historical VAT rate match.',
    ]);

    expect(fn () => $review->handle($acceptedSuggestion, ['reviewer_ref' => 'USR-1']))
        ->toThrow(InvalidCodingSuggestion::class, 'A reviewed suggestion must have a feedback decision and reviewer.');

    expect(fn () => $applyFeedback->handle($acceptedSuggestion, ['decision' => 'maybe']))
        ->toThrow(InvalidCodingSuggestion::class, 'Pending suggestions require an accepted or rejected decision.');

    $afterFeedback = $applyFeedback->handle($acceptedSuggestion, [
        'decision' => 'accepted',
        'note' => 'Looks right',
    ]);

    expect($afterFeedback->status)->toBe(CodingSuggestionStatus::Accepted)
        ->and($afterFeedback->feedback)->toBe(['decision' => 'accepted', 'note' => 'Looks right']);

    expect(fn () => $applyFeedback->handle($afterFeedback, ['decision' => 'rejected']))
        ->toThrow(InvalidCodingSuggestion::class);

    expect(fn () => $review->handle($afterFeedback, ['reviewer_ref' => '']))
        ->toThrow(InvalidCodingSuggestion::class);

    $reviewedAccepted = $review->handle($afterFeedback, [
        'reviewer_ref' => 'CPA-99',
        'approved' => true,
    ]);

    expect($reviewedAccepted->status)->toBe(CodingSuggestionStatus::Reviewed)
        ->and($reviewedAccepted->review)->toBe(['reviewer_ref' => 'CPA-99', 'approved' => true]);

    expect(fn () => $review->handle($reviewedAccepted, ['reviewer_ref' => 'CPA-99']))
        ->toThrow(InvalidCodingSuggestion::class);

    $rejectedSuggestion = $create->handle([
        'team_id' => 20,
        'source_ref' => 'TX-2002',
        'target_type' => 'tax_code',
        'target_ref' => 'VAT-0',
        'confidence' => 0.40,
        'explanation' => 'Low confidence match.',
    ]);

    $afterRejection = $applyFeedback->handle($rejectedSuggestion, ['decision' => 'rejected', 'reason' => 'Wrong code']);
    expect($afterRejection->status)->toBe(CodingSuggestionStatus::Rejected);

    $reviewedRejected = $review->handle($afterRejection, ['reviewer_ref' => 'CPA-100']);
    expect($reviewedRejected->status)->toBe(CodingSuggestionStatus::Reviewed);

    expect(fn () => $setPolicy->handle($reviewedRejected, []))
        ->toThrow(InvalidCodingSuggestion::class, 'Minimum confidence must be between zero and one.');

    expect(fn () => $setPolicy->handle($reviewedRejected, ['minimum_confidence' => -0.1]))
        ->toThrow(InvalidCodingSuggestion::class);

    expect(fn () => $setPolicy->handle($reviewedRejected, ['minimum_confidence' => 1.5]))
        ->toThrow(InvalidCodingSuggestion::class);

    $withPolicy = $setPolicy->handle($reviewedRejected, ['minimum_confidence' => 0.75, 'auto_apply' => false]);
    expect($withPolicy->policy)->toBe(['minimum_confidence' => 0.75, 'auto_apply' => false]);

    $boundaryPolicy = $setPolicy->handle($withPolicy, ['minimum_confidence' => 1]);
    expect($boundaryPolicy->policy)->toBe(['minimum_confidence' => 1]);
});

it('queries coding suggestions per team ordered by latest id', function (): void {
    $create = new CreateCodingSuggestion();
    $query = new CodingSuggestionQuery();

    expect($query->forTeam(999))->toBeEmpty();

    $first = $create->handle([
        'team_id' => 30,
        'source_ref' => 'TX-1',
        'target_type' => 'account',
        'target_ref' => '4000',
        'confidence' => 0.70,
        'explanation' => 'First',
    ]);

    $second = $create->handle([
        'team_id' => 30,
        'source_ref' => 'TX-2',
        'target_type' => 'account',
        'target_ref' => '4001',
        'confidence' => 0.80,
        'explanation' => 'Second',
    ]);

    $create->handle([
        'team_id' => 31,
        'source_ref' => 'TX-3',
        'target_type' => 'account',
        'target_ref' => '4002',
        'confidence' => 0.90,
        'explanation' => 'Other team',
    ]);

    $results = $query->forTeam(30);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});

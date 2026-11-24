<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\MatchingIntelligence\Actions\ConfigureThreshold;
use Liberu\Accounting\MatchingIntelligence\Actions\CreateSuggestion;
use Liberu\Accounting\MatchingIntelligence\Actions\DecideSuggestion;
use Liberu\Accounting\MatchingIntelligence\Actions\RecordFeedback;
use Liberu\Accounting\MatchingIntelligence\Enums\FeedbackType;
use Liberu\Accounting\MatchingIntelligence\Enums\SuggestionStatus;
use Liberu\Accounting\MatchingIntelligence\Events\SuggestionCreated;
use Liberu\Accounting\MatchingIntelligence\Events\SuggestionDecided;
use Liberu\Accounting\MatchingIntelligence\Exceptions\InvalidMatch;
use Liberu\Accounting\MatchingIntelligence\MatchingIntelligenceServiceProvider;
use Liberu\Accounting\MatchingIntelligence\Models\AutomationThreshold;
use Liberu\Accounting\MatchingIntelligence\Models\MatchingEvidence;
use Liberu\Accounting\MatchingIntelligence\Models\MatchingFeedback;
use Liberu\Accounting\MatchingIntelligence\Models\MatchingSuggestion;
use Liberu\Accounting\MatchingIntelligence\Queries\MatchingQuery;

it('registers the service provider, singleton query, and migrations', function (): void {
    expect(app()->getProviders(MatchingIntelligenceServiceProvider::class))->not->toBeEmpty()
        ->and(app(MatchingQuery::class))->toBe(app(MatchingQuery::class))
        ->and(Schema::hasTable('accounting_matching_suggestions'))->toBeTrue()
        ->and(Schema::hasTable('accounting_matching_evidence'))->toBeTrue()
        ->and(Schema::hasTable('accounting_matching_feedback'))->toBeTrue()
        ->and(Schema::hasTable('accounting_matching_thresholds'))->toBeTrue();
});

it('exposes matching enums and exception', function (): void {
    expect(array_map(fn (FeedbackType $t) => $t->value, FeedbackType::cases()))
        ->toBe(['correct', 'incorrect', 'partial'])
        ->and(array_map(fn (SuggestionStatus $s) => $s->value, SuggestionStatus::cases()))
        ->toBe(['suggested', 'accepted', 'rejected', 'automated', 'expired']);

    $exception = new InvalidMatch('Invalid match');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Invalid match');
});

it('configures thresholds, creates suggestions idempotently with evidence, decides, and records feedback', function (): void {
    $configureThreshold = new ConfigureThreshold();
    $createSuggestion = new CreateSuggestion();
    $decideSuggestion = new DecideSuggestion();
    $recordFeedback = new RecordFeedback();

    $threshold = $configureThreshold->handle([
        'team_id' => 3,
        'match_type' => 'invoice_payment',
        'minimum_confidence' => 0.85,
        'maximum_amount' => 5000.00,
        'require_evidence' => true,
        'active' => true,
        'metadata' => ['rule' => 'exact_amount'],
    ]);
    expect($threshold)->toBeInstanceOf(AutomationThreshold::class)
        ->and((float) $threshold->minimum_confidence)->toBe(0.85)
        ->and((float) $threshold->maximum_amount)->toBe(5000.00)
        ->and($threshold->require_evidence)->toBeTrue();

    // Update same threshold via updateOrCreate
    $updatedThreshold = $configureThreshold->handle([
        'team_id' => 3,
        'match_type' => 'invoice_payment',
        'minimum_confidence' => 0.90,
    ]);
    expect($updatedThreshold->id)->toBe($threshold->id)
        ->and((float) $updatedThreshold->minimum_confidence)->toBe(0.90);

    $suggestion = $createSuggestion->handle([
        'team_id' => 3,
        'suggestion_ref' => 'SUG-001',
        'source_type' => 'bank_tx',
        'source_id' => 'TX-10',
        'target_type' => 'invoice',
        'target_id' => 'INV-10',
        'match_type' => 'invoice_payment',
        'confidence' => 0.95,
        'score' => 95.0,
        'automation_threshold' => 0.90,
        'explanation' => 'Exact amount and reference match',
        'algorithm_version' => 'v1',
    ], [
        ['kind' => 'amount', 'field' => 'total', 'source_value' => '100.00', 'target_value' => '100.00', 'weight' => 0.6],
        ['kind' => 'reference', 'field' => 'memo', 'source_value' => 'INV-10', 'target_value' => 'INV-10', 'weight' => 0.4],
    ]);

    expect($suggestion)->toBeInstanceOf(MatchingSuggestion::class)
        ->and($suggestion->status)->toBe(SuggestionStatus::Suggested)
        ->and($suggestion->evidence)->toHaveCount(2)
        ->and($suggestion->evidence->first())->toBeInstanceOf(MatchingEvidence::class)
        ->and($suggestion->evidence->first()->suggestion->is($suggestion))->toBeTrue();

    // Idempotent duplicate suggestion_ref returns existing
    $duplicate = $createSuggestion->handle([
        'team_id' => 3,
        'suggestion_ref' => 'SUG-001',
        'source_type' => 'bank_tx',
        'source_id' => 'TX-10',
        'target_type' => 'invoice',
        'target_id' => 'INV-10',
        'match_type' => 'invoice_payment',
        'confidence' => 0.95,
    ]);
    expect($duplicate->id)->toBe($suggestion->id)
        ->and($duplicate->evidence)->toHaveCount(2);

    // Automate decision
    $automated = $decideSuggestion->handle($suggestion, 'system', accept: true, automate: true);
    expect($automated->status)->toBe(SuggestionStatus::Automated)
        ->and($automated->metadata['decided_by'])->toBe('system');

    // Accept and reject decisions
    $s2 = $createSuggestion->handle([
        'team_id' => 3,
        'suggestion_ref' => 'SUG-002',
        'source_type' => 'bank_tx',
        'source_id' => 'TX-11',
        'target_type' => 'invoice',
        'target_id' => 'INV-11',
        'match_type' => 'invoice_payment',
        'confidence' => 0.70,
    ]);
    $accepted = $decideSuggestion->handle($s2, 'user-1', accept: true, automate: false);
    expect($accepted->status)->toBe(SuggestionStatus::Accepted);

    $s3 = $createSuggestion->handle([
        'team_id' => 3,
        'suggestion_ref' => 'SUG-003',
        'source_type' => 'bank_tx',
        'source_id' => 'TX-12',
        'target_type' => 'invoice',
        'target_id' => 'INV-12',
        'match_type' => 'invoice_payment',
        'confidence' => 0.50,
    ]);
    $rejected = $decideSuggestion->handle($s3, 'user-1', accept: false, automate: false);
    expect($rejected->status)->toBe(SuggestionStatus::Rejected);

    // Feedback recording
    $fb = $recordFeedback->handle($rejected, 'user-1', 'incorrect', 'Wrong customer');
    expect($fb)->toBeInstanceOf(MatchingFeedback::class)
        ->and($fb->feedback_type)->toBe(FeedbackType::Incorrect)
        ->and($fb->suggestion->is($rejected))->toBeTrue()
        ->and($rejected->feedback()->count())->toBe(1);

    // Events
    Event::fake([SuggestionCreated::class, SuggestionDecided::class]);
    SuggestionCreated::dispatch($s2);
    SuggestionDecided::dispatch($accepted);
    Event::assertDispatched(SuggestionCreated::class);
    Event::assertDispatched(SuggestionDecided::class);
});

it('throws InvalidMatch on invalid threshold, suggestion, decision, and feedback inputs', function (): void {
    $configureThreshold = new ConfigureThreshold();
    $createSuggestion = new CreateSuggestion();
    $decideSuggestion = new DecideSuggestion();
    $recordFeedback = new RecordFeedback();

    expect(fn () => $configureThreshold->handle(['match_type' => '', 'minimum_confidence' => 0.5]))
        ->toThrow(InvalidMatch::class);
    expect(fn () => $configureThreshold->handle(['match_type' => 'x', 'minimum_confidence' => 1.5]))
        ->toThrow(InvalidMatch::class);

    expect(fn () => $createSuggestion->handle([
        'suggestion_ref' => 'S1',
        'source_type' => 'tx',
        'confidence' => 0.5,
    ]))->toThrow(InvalidMatch::class, 'Missing matching field [source_id].');

    expect(fn () => $createSuggestion->handle([
        'suggestion_ref' => ' ',
        'source_type' => 'tx',
        'source_id' => '1',
        'target_type' => 'inv',
        'target_id' => '2',
        'match_type' => 'exact',
        'confidence' => 0.5,
    ]))->toThrow(InvalidMatch::class, 'Suggestion reference and confidence from zero to one are required.');

    expect(fn () => $createSuggestion->handle([
        'suggestion_ref' => 'S-BAD-EV',
        'source_type' => 'tx',
        'source_id' => '1',
        'target_type' => 'inv',
        'target_id' => '2',
        'match_type' => 'exact',
        'confidence' => 0.5,
    ], [['field' => 'no-kind']]))->toThrow(InvalidMatch::class, 'Every evidence item requires a kind.');

    $lowConf = $createSuggestion->handle([
        'suggestion_ref' => 'S-LOW',
        'source_type' => 'tx',
        'source_id' => '2',
        'target_type' => 'inv',
        'target_id' => '3',
        'match_type' => 'exact',
        'confidence' => 0.60,
        'automation_threshold' => 0.85,
    ]);

    expect(fn () => $decideSuggestion->handle($lowConf, 'bot', true, true))
        ->toThrow(InvalidMatch::class, 'Confidence is below the safe automation threshold.');

    $decided = $decideSuggestion->handle($lowConf, 'human', true, false);
    expect(fn () => $decideSuggestion->handle($decided, 'human', false, false))
        ->toThrow(InvalidMatch::class, 'Only suggested matches can be decided.');

    expect(fn () => $recordFeedback->handle($decided, 'human', 'bogus'))
        ->toThrow(InvalidMatch::class, 'Feedback must be correct, incorrect, or partial.');
});

it('queries suggestions and automation-eligible suggestions with filters and clamping', function (): void {
    $query = app(MatchingQuery::class);
    expect($query->suggestions()->total())->toBe(0)
        ->and($query->automationEligible()->total())->toBe(0);

    $createSuggestion = new CreateSuggestion();
    $decideSuggestion = new DecideSuggestion();

    $eligible = $createSuggestion->handle([
        'team_id' => 7,
        'suggestion_ref' => 'ELIG-1',
        'source_type' => 'tx',
        'source_id' => '1',
        'target_type' => 'inv',
        'target_id' => '1',
        'match_type' => 'exact',
        'confidence' => 0.92,
        'automation_threshold' => 0.85,
    ]);
    $below = $createSuggestion->handle([
        'team_id' => 7,
        'suggestion_ref' => 'BELOW-1',
        'source_type' => 'tx',
        'source_id' => '2',
        'target_type' => 'inv',
        'target_id' => '2',
        'match_type' => 'exact',
        'confidence' => 0.70,
        'automation_threshold' => 0.85,
    ]);
    $accepted = $createSuggestion->handle([
        'team_id' => 8,
        'suggestion_ref' => 'ACC-1',
        'source_type' => 'tx',
        'source_id' => '3',
        'target_type' => 'inv',
        'target_id' => '3',
        'match_type' => 'exact',
        'confidence' => 0.99,
        'automation_threshold' => 0.85,
    ]);
    $decideSuggestion->handle($accepted, 'u1', true, false);

    expect($query->suggestions()->total())->toBe(3)
        ->and($query->suggestions(teamId: 7)->total())->toBe(2)
        ->and($query->suggestions(status: 'accepted')->total())->toBe(1)
        ->and($query->suggestions(perPage: 0)->perPage())->toBe(1)
        ->and($query->suggestions(perPage: 500)->perPage())->toBe(100)
        ->and($query->automationEligible()->total())->toBe(1)
        ->and($query->automationEligible(teamId: 7)->items()[0]->is($eligible))->toBeTrue()
        ->and($query->automationEligible(teamId: 8)->total())->toBe(0)
        ->and($query->automationEligible(perPage: 200)->perPage())->toBe(100);
});

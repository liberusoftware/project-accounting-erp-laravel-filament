<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\JobEstimates\Actions\AddEstimateLine;
use Liberu\Accounting\JobEstimates\Actions\CreateEstimate;
use Liberu\Accounting\JobEstimates\Actions\CreateVersion;
use Liberu\Accounting\JobEstimates\Actions\DecideEstimate;
use Liberu\Accounting\JobEstimates\Actions\RecordActual;
use Liberu\Accounting\JobEstimates\Actions\SubmitEstimate;
use Liberu\Accounting\JobEstimates\Enums\DecisionType;
use Liberu\Accounting\JobEstimates\Enums\EstimateStatus;
use Liberu\Accounting\JobEstimates\Enums\LineType;
use Liberu\Accounting\JobEstimates\Events\EstimateApproved;
use Liberu\Accounting\JobEstimates\Exceptions\InvalidEstimate;
use Liberu\Accounting\JobEstimates\JobEstimatesServiceProvider;
use Liberu\Accounting\JobEstimates\Models\EstimateActual;
use Liberu\Accounting\JobEstimates\Models\EstimateApproval;
use Liberu\Accounting\JobEstimates\Models\EstimateLine;
use Liberu\Accounting\JobEstimates\Models\EstimateVersion;
use Liberu\Accounting\JobEstimates\Models\JobEstimate;
use Liberu\Accounting\JobEstimates\Queries\JobEstimateQuery;

it('boots job estimates service provider and covers enums, exception, and event', function (): void {
    $provider = $this->app->register(JobEstimatesServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(JobEstimatesServiceProvider::class)
        ->and(app(JobEstimateQuery::class))->toBe(app(JobEstimateQuery::class))
        ->and(array_column(DecisionType::cases(), 'value'))->toBe(['approved', 'rejected'])
        ->and(array_column(EstimateStatus::cases(), 'value'))->toBe(['draft', 'submitted', 'approved', 'rejected', 'archived'])
        ->and(array_column(LineType::cases(), 'value'))->toBe(['cost', 'revenue'])
        ->and((new InvalidEstimate('Estimate error'))->getMessage())->toBe('Estimate error');

    Event::fake([EstimateApproved::class]);
    $estimate = app(CreateEstimate::class)->handle([
        'team_id' => 1,
        'estimate_ref' => 'EST-EVT-1',
        'project_ref' => 'PRJ-1',
        'title' => 'Event Test Estimate',
        'currency' => 'USD',
    ]);
    $event = new EstimateApproved($estimate);
    event($event);

    expect($event->estimate->is($estimate))->toBeTrue();
    Event::assertDispatched(EstimateApproved::class);
});

it('executes estimate creation, lines, submission, rejection, approval, versioning, actuals, and queries', function (): void {
    $query = app(JobEstimateQuery::class);
    expect($query->paginate(7)->total())->toBe(0);

    $estimate = app(CreateEstimate::class)->handle([
        'team_id' => 7,
        'estimate_ref' => 'EST-2026-001',
        'project_ref' => 'PRJ-ALPHA',
        'title' => 'Warehouse Fitout',
        'currency' => 'eur',
        'metadata' => ['client' => 'Acme'],
    ]);

    expect($estimate)->toBeInstanceOf(JobEstimate::class)
        ->and($estimate->currency)->toBe('EUR')
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->version_no)->toBe(1)
        ->and($estimate->metadata)->toBe(['client' => 'Acme']);

    $addLine = app(AddEstimateLine::class);
    $costLine = $addLine->handle($estimate, [
        'line_ref' => 'L-COST-1',
        'line_type' => LineType::Cost->value,
        'category' => 'materials',
        'description' => 'Steel beams',
        'quantity' => 10,
        'rate' => 150.00,
        'metadata' => ['supplier' => 'SteelCo'],
    ]);
    $revenueLine = $addLine->handle($estimate, [
        'line_ref' => 'L-REV-1',
        'line_type' => LineType::Revenue->value,
        'category' => 'milestone',
        'description' => 'Phase 1 billing',
        'quantity' => 1,
        'rate' => 3000.00,
    ]);

    $estimate->refresh();
    expect($costLine)->toBeInstanceOf(EstimateLine::class)
        ->and($costLine->estimate->is($estimate))->toBeTrue()
        ->and($costLine->version)->toBeNull()
        ->and((float) $costLine->amount)->toBe(1500.0)
        ->and((float) $estimate->total_cost)->toBe(1500.0)
        ->and((float) $estimate->total_revenue)->toBe(3000.0)
        ->and($revenueLine->line_type)->toBe(LineType::Revenue);

    // Submit then reject
    $submitted = app(SubmitEstimate::class)->handle($estimate);
    expect($submitted->status)->toBe(EstimateStatus::Submitted);

    $rejected = app(DecideEstimate::class)->handle($submitted, 'approver-1', false, 'Reduce material margin');
    expect($rejected->status)->toBe(EstimateStatus::Rejected);

    // Rejected estimate can be edited and resubmitted
    $extraCost = $addLine->handle($rejected, [
        'line_ref' => 'L-COST-2',
        'line_type' => LineType::Cost->value,
        'category' => 'labour',
        'description' => 'Installation crew',
        'quantity' => 5,
        'rate' => 100.00,
    ]);
    expect((float) $extraCost->amount)->toBe(500.0);

    $resubmitted = app(SubmitEstimate::class)->handle($rejected->refresh());
    $approved = app(DecideEstimate::class)->handle($resubmitted, 'approver-2', true, 'Approved for execution');

    expect($approved->status)->toBe(EstimateStatus::Approved)
        ->and($approved->approvals()->count())->toBe(2);

    // Record actuals (with line_ref and without line_ref)
    $recordActual = app(RecordActual::class);
    $actualWithLine = $recordActual->handle($approved, [
        'line_ref' => 'L-COST-1',
        'category' => 'materials',
        'amount' => 1200.00,
        'source_ref' => 'BILL-001',
        'occurred_at' => now(),
        'metadata' => ['invoice' => 'INV-1'],
    ]);
    $actualWithoutLine = $recordActual->handle($approved, [
        'category' => 'overhead',
        'amount' => 250.00,
        'source_ref' => 'JRN-001',
        'occurred_at' => now(),
    ]);

    expect($actualWithLine)->toBeInstanceOf(EstimateActual::class)
        ->and($actualWithLine->estimate->is($approved))->toBeTrue()
        ->and($actualWithLine->version)->toBeNull()
        ->and($actualWithoutLine->line_ref)->toBeNull()
        ->and($approved->actuals()->count())->toBe(2);

    // Comparison and EAC queries
    $comparison = $query->comparison($approved->refresh());
    expect($comparison)->toHaveCount(3)
        ->and($comparison[0])->toBe([
            'line_ref' => 'L-COST-1',
            'estimate' => 1500.0,
            'actual' => 1200.0,
            'variance' => -300.0,
        ]);

    $eac = $query->estimateAtCompletion($approved);
    expect($eac)->toBe([
        'actual_cost' => 1200.0,
        'remaining_cost' => 800.0,
        'estimate_at_completion' => 2000.0,
        'approved_budget' => 2000.0,
    ]);

    // Create a new version (clones lines and resets estimate to draft)
    $version = app(CreateVersion::class)->handle($approved, 'Change order #1');
    $approved->refresh();

    expect($version)->toBeInstanceOf(EstimateVersion::class)
        ->and($version->version_no)->toBe(2)
        ->and($version->estimate->is($approved))->toBeTrue()
        ->and($version->lines)->toHaveCount(3)
        ->and($version->lines->first()->version->is($version))->toBeTrue()
        ->and($approved->version_no)->toBe(2)
        ->and($approved->status)->toBe(EstimateStatus::Draft)
        ->and($approved->versions()->count())->toBe(1);

    // Link an approval and actual to version to cover version relations
    $approval = $approved->approvals()->first();
    $approval->update(['version_id' => $version->id]);
    $approval->refresh();

    expect($approval)->toBeInstanceOf(EstimateApproval::class)
        ->and($approval->estimate->is($approved))->toBeTrue()
        ->and($approval->version->is($version))->toBeTrue()
        ->and($version->approvals()->count())->toBe(1);

    $versionedActual = $recordActual->handle($approved, [
        'version_id' => $version->id,
        'category' => 'misc',
        'amount' => 50.00,
        'source_ref' => 'BILL-002',
        'occurred_at' => now(),
    ]);
    expect($versionedActual->version->is($version))->toBeTrue();

    // Query paginate filters & bounds
    $page = $query->paginate(7, EstimateStatus::Draft, 200);
    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100);

    $emptyPage = $query->paginate(7, EstimateStatus::Archived, 0);
    expect($emptyPage->total())->toBe(0)
        ->and($emptyPage->perPage())->toBe(1);
});

it('enforces validation and state transitions across job estimate actions', function (): void {
    $create = app(CreateEstimate::class);
    $valid = [
        'estimate_ref' => 'EST-VAL-1',
        'project_ref' => 'PRJ-VAL',
        'title' => 'Validation Job',
        'currency' => 'USD',
    ];

    foreach (['project_ref', 'title', 'currency'] as $field) {
        expect(fn () => $create->handle(array_diff_key($valid, [$field => true])))
            ->toThrow(InvalidEstimate::class, "Missing estimate field [{$field}].");
    }

    expect(fn () => $create->handle([...$valid, 'estimate_ref' => '   ']))
        ->toThrow(InvalidEstimate::class, 'Estimate reference is required.');

    $draft = $create->handle($valid);

    // Cannot submit without lines or decide draft
    expect(fn () => app(SubmitEstimate::class)->handle($draft))
        ->toThrow(InvalidEstimate::class, 'at least one line')
        ->and(fn () => app(DecideEstimate::class)->handle($draft, 'approver', true))
        ->toThrow(InvalidEstimate::class, 'Only submitted estimates can be decided.');

    // AddEstimateLine validations
    $addLine = app(AddEstimateLine::class);
    $validLine = [
        'line_ref' => 'L-1',
        'line_type' => 'cost',
        'category' => 'materials',
        'description' => 'Concrete',
        'quantity' => 2,
        'rate' => 50,
    ];

    foreach (['line_ref', 'category', 'description'] as $field) {
        expect(fn () => $addLine->handle($draft, array_diff_key($validLine, [$field => true])))
            ->toThrow(InvalidEstimate::class, "Missing line field [{$field}].");
    }

    expect(fn () => $addLine->handle($draft, [...$validLine, 'line_type' => 'invalid']))
        ->toThrow(InvalidEstimate::class, 'non-negative values')
        ->and(fn () => $addLine->handle($draft, [...$validLine, 'quantity' => -1]))
        ->toThrow(InvalidEstimate::class, 'non-negative values')
        ->and(fn () => $addLine->handle($draft, [...$validLine, 'rate' => -5]))
        ->toThrow(InvalidEstimate::class, 'non-negative values');

    $addLine->handle($draft, $validLine);
    $submitted = app(SubmitEstimate::class)->handle($draft);

    // Submitted estimate cannot add lines, be re-submitted, or be rejected without comment
    expect(fn () => $addLine->handle($submitted, [...$validLine, 'line_ref' => 'L-2']))
        ->toThrow(InvalidEstimate::class, 'draft estimate')
        ->and(fn () => app(SubmitEstimate::class)->handle($submitted))
        ->toThrow(InvalidEstimate::class, 'Only draft or rejected')
        ->and(fn () => app(DecideEstimate::class)->handle($submitted, 'approver', false, ''))
        ->toThrow(InvalidEstimate::class, 'Rejected estimates require a comment.');

    // Archived estimate cannot be versioned
    $submitted->update(['status' => EstimateStatus::Archived]);
    expect(fn () => app(CreateVersion::class)->handle($submitted->refresh()))
        ->toThrow(InvalidEstimate::class, 'Archived estimates cannot be versioned.');

    // RecordActual validations
    $recordActual = app(RecordActual::class);
    $validActual = [
        'category' => 'materials',
        'source_ref' => 'INV-1',
        'occurred_at' => now(),
        'amount' => 10,
    ];

    foreach (['category', 'source_ref', 'occurred_at'] as $field) {
        expect(fn () => $recordActual->handle($submitted, array_diff_key($validActual, [$field => true])))
            ->toThrow(InvalidEstimate::class, "Missing actual field [{$field}].");
    }

    expect(fn () => $recordActual->handle($submitted, [...$validActual, 'amount' => -1]))
        ->toThrow(InvalidEstimate::class, 'Actual amount cannot be negative.');
});

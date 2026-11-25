<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\JournalApprovals\Actions\AddEvidence;
use Liberu\Accounting\JournalApprovals\Actions\ConfigureThreshold;
use Liberu\Accounting\JournalApprovals\Actions\DecideJournal;
use Liberu\Accounting\JournalApprovals\Actions\PostJournal;
use Liberu\Accounting\JournalApprovals\Actions\SubmitJournal;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovals\Enums\DecisionType;
use Liberu\Accounting\JournalApprovals\Events\JournalApproved;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;
use Liberu\Accounting\JournalApprovals\JournalApprovalsServiceProvider;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;
use Liberu\Accounting\JournalApprovals\Models\JournalDecision;
use Liberu\Accounting\JournalApprovals\Models\JournalEvidence;
use Liberu\Accounting\JournalApprovals\Queries\JournalApprovalQuery;

it('boots journal approvals service provider and covers enums, exception, and event', function (): void {
    $provider = $this->app->register(JournalApprovalsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(JournalApprovalsServiceProvider::class)
        ->and(app(JournalApprovalQuery::class))->toBe(app(JournalApprovalQuery::class))
        ->and(array_column(ApprovalStatus::cases(), 'value'))->toBe(['draft', 'pending', 'approved', 'rejected', 'posted', 'emergency_posted'])
        ->and(array_column(DecisionType::cases(), 'value'))->toBe(['approved', 'rejected', 'requested', 'emergency'])
        ->and((new InvalidApproval('Approval error'))->getMessage())->toBe('Approval error');

    Event::fake([JournalApproved::class]);
    $approval = app(SubmitJournal::class)->handle([
        'team_id' => 1,
        'approval_ref' => 'JAP-EVT-001',
        'journal_type' => 'manual',
        'journal_source' => 'gl',
        'journal_ref' => 'JE-EVT-001',
        'preparer_ref' => 'user-1',
        'currency' => 'USD',
        'amount' => 500.00,
    ]);
    $event = new JournalApproved($approval);
    event($event);

    expect($event->approval->is($approval))->toBeTrue();
    Event::assertDispatched(JournalApproved::class);
});

it('executes threshold configuration, submission, evidence, decisions, posting, and queries', function (): void {
    $query = app(JournalApprovalQuery::class);
    expect($query->paginate(3)->total())->toBe(0);

    // Configure and update threshold
    $configure = app(ConfigureThreshold::class);
    $threshold = $configure->handle([
        'team_id' => 3,
        'journal_type' => 'manual',
        'minimum_amount' => 1000.00,
        'reviewer_role' => 'controller',
        'required_approvals' => 2,
        'emergency_allowed' => true,
        'active' => true,
        'metadata' => ['policy' => 'SOX-404'],
    ]);

    expect($threshold)->toBeInstanceOf(ApprovalThreshold::class)
        ->and((float) $threshold->minimum_amount)->toBe(1000.0)
        ->and($threshold->required_approvals)->toBe(2)
        ->and($threshold->emergency_allowed)->toBeTrue()
        ->and($threshold->active)->toBeTrue()
        ->and($threshold->metadata)->toBe(['policy' => 'SOX-404']);

    // Submit journal with initial evidence
    $submit = app(SubmitJournal::class);
    $payload = [
        'team_id' => 3,
        'approval_ref' => 'JAP-2026-001',
        'journal_type' => 'manual',
        'journal_source' => 'close',
        'journal_ref' => 'JE-1001',
        'preparer_ref' => 'accountant-1',
        'reviewer_ref' => 'controller-1',
        'currency' => 'gbp',
        'amount' => 15000.50,
        'threshold_amount' => 1000.00,
        'metadata' => ['period' => '2026-03'],
    ];

    $approval = $submit->handle($payload, [
        [
            'kind' => 'support_schedule',
            'file_ref' => 'docs/accrual.pdf',
            'description' => 'Accrual calculation',
            'checksum' => 'sha256:abc123',
            'metadata' => ['pages' => 2],
        ],
    ]);

    expect($approval)->toBeInstanceOf(JournalApproval::class)
        ->and($approval->currency)->toBe('GBP')
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->evidence)->toHaveCount(1);

    // Idempotent re-submission returns existing approval
    $duplicate = $submit->handle($payload);
    expect($duplicate->is($approval))->toBeTrue()
        ->and($duplicate->evidence)->toHaveCount(1);

    // Add additional evidence
    $extraEvidence = app(AddEvidence::class)->handle($approval, [
        'kind' => 'email_signoff',
        'file_ref' => 'docs/signoff.eml',
        'description' => 'CFO email confirmation',
    ]);

    expect($extraEvidence)->toBeInstanceOf(JournalEvidence::class)
        ->and($extraEvidence->approval->is($approval))->toBeTrue()
        ->and($approval->evidence()->count())->toBe(2);

    // Approve and post
    $approved = app(DecideJournal::class)->handle($approval, 'controller-1', true, 'Verified against subledger');
    expect($approved->status)->toBe(ApprovalStatus::Approved)
        ->and($approved->reviewer_ref)->toBe('controller-1')
        ->and($approved->decided_at)->not->toBeNull();

    $decision = $approved->decisions()->first();
    expect($decision)->toBeInstanceOf(JournalDecision::class)
        ->and($decision->decision)->toBe(DecisionType::Approved)
        ->and($decision->approval->is($approved))->toBeTrue();

    $posted = app(PostJournal::class)->handle($approved);
    expect($posted->status)->toBe(ApprovalStatus::Posted)
        ->and($posted->posted_at)->not->toBeNull();

    // Submit another journal, reject it, and test emergency posting on a third
    $toReject = $submit->handle([
        ...$payload,
        'approval_ref' => 'JAP-2026-002',
        'journal_ref' => 'JE-1002',
    ]);
    $rejected = app(DecideJournal::class)->handle($toReject, 'controller-2', false, 'Missing supporting schedule');
    expect($rejected->status)->toBe(ApprovalStatus::Rejected)
        ->and($rejected->decisions()->first()->decision)->toBe(DecisionType::Rejected);

    $forEmergency = $submit->handle([
        ...$payload,
        'approval_ref' => 'JAP-2026-003',
        'journal_ref' => 'JE-1003',
    ]);
    $emergencyPosted = app(PostJournal::class)->handle($forEmergency, true, 'Regulatory filing deadline');
    expect($emergencyPosted->status)->toBe(ApprovalStatus::EmergencyPosted)
        ->and($emergencyPosted->emergency_reason)->toBe('Regulatory filing deadline')
        ->and($emergencyPosted->posted_at)->not->toBeNull();

    // Query filters & pagination clamping
    $postedPage = $query->paginate(3, ApprovalStatus::Posted, 200);
    expect($postedPage->total())->toBe(1)
        ->and($postedPage->perPage())->toBe(100);

    $allForTeam = $query->paginate(3, null, 0);
    expect($allForTeam->total())->toBe(3)
        ->and($allForTeam->perPage())->toBe(1);
});

it('enforces validation and state transitions across journal approval actions', function (): void {
    $configure = app(ConfigureThreshold::class);
    expect(fn () => $configure->handle(['minimum_amount' => 100, 'reviewer_role' => 'cfo']))
        ->toThrow(InvalidApproval::class)
        ->and(fn () => $configure->handle(['journal_type' => 'manual', 'minimum_amount' => -1, 'reviewer_role' => 'cfo']))
        ->toThrow(InvalidApproval::class)
        ->and(fn () => $configure->handle(['journal_type' => 'manual', 'minimum_amount' => 100]))
        ->toThrow(InvalidApproval::class);

    $submit = app(SubmitJournal::class);
    $valid = [
        'approval_ref' => 'JAP-VAL-001',
        'journal_type' => 'manual',
        'journal_source' => 'gl',
        'journal_ref' => 'JE-VAL-001',
        'preparer_ref' => 'user-1',
        'currency' => 'USD',
        'amount' => 250.00,
    ];

    foreach (['journal_type', 'journal_source', 'journal_ref', 'preparer_ref', 'currency'] as $field) {
        expect(fn () => $submit->handle(array_diff_key($valid, [$field => true])))
            ->toThrow(InvalidApproval::class, "Missing journal field [{$field}].");
    }

    expect(fn () => $submit->handle([...$valid, 'approval_ref' => '  ']))
        ->toThrow(InvalidApproval::class, 'Approval reference and positive journal amount are required.')
        ->and(fn () => $submit->handle([...$valid, 'amount' => 0]))
        ->toThrow(InvalidApproval::class, 'Approval reference and positive journal amount are required.')
        ->and(fn () => $submit->handle($valid, ['not-an-array']))
        ->toThrow(InvalidApproval::class, 'Each evidence item requires a kind.')
        ->and(fn () => $submit->handle($valid, [['description' => 'missing kind']]))
        ->toThrow(InvalidApproval::class, 'Each evidence item requires a kind.');

    $pending = $submit->handle($valid);

    expect(fn () => app(AddEvidence::class)->handle($pending, ['description' => 'No kind']))
        ->toThrow(InvalidApproval::class, 'Evidence kind is required.')
        ->and(fn () => app(DecideJournal::class)->handle($pending, 'reviewer', false, ''))
        ->toThrow(InvalidApproval::class, 'Rejection requires a comment.')
        ->and(fn () => app(PostJournal::class)->handle($pending))
        ->toThrow(InvalidApproval::class, 'Only approved journals can be posted.')
        ->and(fn () => app(PostJournal::class)->handle($pending, true, ''))
        ->toThrow(InvalidApproval::class, 'Emergency posting requires a reason.');

    $approved = app(DecideJournal::class)->handle($pending, 'reviewer', true);
    expect(fn () => app(DecideJournal::class)->handle($approved, 'reviewer-2', true))
        ->toThrow(InvalidApproval::class, 'Only pending journals can be decided.');
});

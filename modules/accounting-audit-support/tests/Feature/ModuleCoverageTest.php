<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Liberu\Accounting\AuditSupport\Actions\CreateAuditRequest;
use Liberu\Accounting\AuditSupport\Actions\SubmitAuditRequest;
use Liberu\Accounting\AuditSupport\AuditSupportServiceProvider;
use Liberu\Accounting\AuditSupport\Enums\AuditRequestStatus;
use Liberu\Accounting\AuditSupport\Models\AuditRequest;
use Liberu\Accounting\AuditSupport\Queries\AuditRequestQuery;

it('boots the service provider and covers enum, model, actions, and query', function (): void {
    $provider = $this->app->register(AuditSupportServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AuditSupportServiceProvider::class);

    expect(array_map(fn (AuditRequestStatus $s): string => $s->value, AuditRequestStatus::cases()))
        ->toBe(['open', 'in_progress', 'submitted', 'closed']);

    $create = app(CreateAuditRequest::class);
    $submit = app(SubmitAuditRequest::class);
    $query = app(AuditRequestQuery::class);

    expect($query->paginate(1)->total())->toBe(0);

    // Validation branches
    expect(fn () => $create->handle(['title' => 'Bank Statements']))
        ->toThrow(InvalidArgumentException::class, 'team_id is required.');
    expect(fn () => $create->handle(['team_id' => 1]))
        ->toThrow(InvalidArgumentException::class, 'title is required.');

    // Create with auto-generated reference
    $openReq = $create->handle([
        'team_id' => 1,
        'title' => 'Provide Q1 Bank Statements',
        'description' => 'PDF and CSV exports',
        'owner_id' => '9',
        'due_at' => '2026-06-01 17:00:00',
        'evidence' => [['file' => 'bank.pdf']],
    ]);

    expect($openReq)->toBeInstanceOf(AuditRequest::class)
        ->and($openReq->status)->toBe(AuditRequestStatus::Open)
        ->and($openReq->reference)->toStartWith('AUD-')
        ->and(strlen($openReq->reference))->toBe(12)
        ->and($openReq->owner_id)->toBe(9)
        ->and($openReq->due_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($openReq->evidence)->toBe([['file' => 'bank.pdf']]);

    // Submit from Open
    $submitted = $submit->handle($openReq);
    expect($submitted->status)->toBe(AuditRequestStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull();

    // Fail when already Submitted
    expect(fn () => $submit->handle($submitted->fresh()))
        ->toThrow(InvalidArgumentException::class, 'Only open requests may be submitted.');

    // Submit from InProgress
    $inProgressReq = $create->handle([
        'team_id' => 1,
        'reference' => 'AUD-CUSTOM1',
        'title' => 'Provide Payroll Register',
    ]);
    $inProgressReq->update(['status' => AuditRequestStatus::InProgress]);
    $submitted2 = $submit->handle($inProgressReq->fresh());
    expect($submitted2->status)->toBe(AuditRequestStatus::Submitted)
        ->and($submitted2->reference)->toBe('AUD-CUSTOM1');

    // Closed request cannot be submitted
    $closedReq = $create->handle([
        'team_id' => 1,
        'reference' => 'AUD-CLOSED',
        'title' => 'Closed Request',
    ]);
    $closedReq->update(['status' => AuditRequestStatus::Closed, 'closed_at' => now()]);
    expect($closedReq->fresh()->closed_at)->not->toBeNull();
    expect(fn () => $submit->handle($closedReq->fresh()))
        ->toThrow(InvalidArgumentException::class, 'Only open requests may be submitted.');

    // Another team
    $create->handle([
        'team_id' => 2,
        'title' => 'Team 2 Audit',
    ]);

    // Query filtering and clamping
    expect($query->paginate(1)->total())->toBe(3)
        ->and($query->paginate(1, AuditRequestStatus::Submitted->value)->total())->toBe(2)
        ->and($query->paginate(1, AuditRequestStatus::Closed->value)->total())->toBe(1)
        ->and($query->paginate(1, null, 0)->perPage())->toBe(1)
        ->and($query->paginate(1, null, 500)->perPage())->toBe(100);
});

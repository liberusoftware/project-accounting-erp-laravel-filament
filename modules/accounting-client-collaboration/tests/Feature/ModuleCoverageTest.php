<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ClientCollaboration\Actions\CreateCollaborationThread;
use Liberu\Accounting\ClientCollaboration\Actions\RecordCollaborationActivity;
use Liberu\Accounting\ClientCollaboration\Actions\ResolveCollaboration;
use Liberu\Accounting\ClientCollaboration\ClientCollaborationServiceProvider;
use Liberu\Accounting\ClientCollaboration\Enums\CollaborationStatus;
use Liberu\Accounting\ClientCollaboration\Exceptions\InvalidCollaboration;
use Liberu\Accounting\ClientCollaboration\Models\CollaborationThread;
use Liberu\Accounting\ClientCollaboration\Queries\CollaborationQuery;

it('boots the client collaboration service provider and schema', function (): void {
    $provider = app()->getProvider(ClientCollaborationServiceProvider::class);
    expect($provider)->toBeInstanceOf(ClientCollaborationServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_collaboration_threads'))->toBeTrue();

    expect(array_map(static fn (CollaborationStatus $s): string => $s->value, CollaborationStatus::cases()))
        ->toBe(['open', 'pending-approval', 'approved', 'closed']);

    $exception = new InvalidCollaboration('Bad thread');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad thread');
});

it('creates collaboration threads for supported kinds and validates input', function (): void {
    $create = new CreateCollaborationThread();

    foreach (['team_id', 'thread_ref', 'kind', 'subject'] as $field) {
        $payload = [
            'team_id' => 1,
            'thread_ref' => 'THR-1',
            'kind' => 'question',
            'subject' => 'Missing receipt',
        ];
        unset($payload[$field]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidCollaboration::class, "{$field} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 1,
        'thread_ref' => 'THR-BAD',
        'kind' => 'chat',
        'subject' => 'Unsupported',
    ]))->toThrow(InvalidCollaboration::class, 'Unsupported collaboration kind.');

    foreach (['document-request', 'question', 'task', 'discussion'] as $idx => $kind) {
        $thread = $create->handle([
            'team_id' => 1,
            'thread_ref' => "THR-{$idx}",
            'kind' => $kind,
            'subject' => "Subject {$kind}",
            'participants' => [['ref' => 'client-1']],
        ]);

        expect($thread)->toBeInstanceOf(CollaborationThread::class)
            ->and($thread->status)->toBe(CollaborationStatus::Open)
            ->and($thread->participants)->toBe([['ref' => 'client-1']]);
    }
});

it('records collaboration activity and resolves approvals and closure', function (): void {
    $thread = (new CreateCollaborationThread())->handle([
        'team_id' => 2,
        'thread_ref' => 'THR-FLOW',
        'kind' => 'document-request',
        'subject' => 'Q3 VAT invoices',
    ]);

    $activity = new RecordCollaborationActivity();
    $resolve = new ResolveCollaboration();

    expect(fn () => $activity->message($thread, ['author' => 'accountant']))
        ->toThrow(InvalidCollaboration::class, 'Open threads require a message body.');
    expect(fn () => $activity->approval($thread, ['note' => 'missing approver']))
        ->toThrow(InvalidCollaboration::class, 'Approver reference is required.');
    expect(fn () => $activity->reminder($thread, ['channel' => 'email']))
        ->toThrow(InvalidCollaboration::class, 'Reminder due time is required.');
    expect(fn () => $activity->evidence($thread, ['filename' => 'vat.pdf']))
        ->toThrow(InvalidCollaboration::class, 'Evidence reference is required.');

    // Cannot approve while status is Open
    expect(fn () => $resolve->approve($thread, 'cfo@client.test'))
        ->toThrow(InvalidCollaboration::class, 'Only pending approvals can be approved.');

    $thread = $activity->message($thread, ['author' => 'accountant', 'body' => 'Please upload PDF']);
    $thread = $activity->reminder($thread, ['due_at' => '2026-09-10T10:00:00Z']);
    $thread = $activity->evidence($thread, ['reference' => 'DOC-99']);
    $thread = $activity->approval($thread, ['approver_ref' => 'cfo@client.test']);

    expect($thread->messages)->toHaveCount(1)
        ->and($thread->reminders)->toHaveCount(1)
        ->and($thread->evidence)->toHaveCount(1)
        ->and($thread->approvals)->toHaveCount(1)
        ->and($thread->status)->toBe(CollaborationStatus::PendingApproval);

    // Blank approver rejected
    expect(fn () => $resolve->approve($thread, '   '))
        ->toThrow(InvalidCollaboration::class, 'Only pending approvals can be approved.');

    $thread = $resolve->approve($thread, 'cfo@client.test');
    expect($thread->status)->toBe(CollaborationStatus::Approved)
        ->and($thread->approvals)->toHaveCount(2)
        ->and($thread->approvals[1])->toBe(['approved_by' => 'cfo@client.test']);

    $closed = $resolve->close($thread);
    expect($closed->status)->toBe(CollaborationStatus::Closed);

    expect(fn () => $resolve->close($closed))
        ->toThrow(InvalidCollaboration::class, 'Thread is already closed.');
    expect(fn () => $activity->message($closed, ['body' => 'After close']))
        ->toThrow(InvalidCollaboration::class, 'Open threads require a message body.');
});

it('queries collaboration threads by team', function (): void {
    $query = new CollaborationQuery();
    expect($query->forTeam(404))->toBeEmpty();

    $create = new CreateCollaborationThread();
    $first = $create->handle(['team_id' => 12, 'thread_ref' => 'T-1', 'kind' => 'task', 'subject' => 'First']);
    $second = $create->handle(['team_id' => 12, 'thread_ref' => 'T-2', 'kind' => 'discussion', 'subject' => 'Second']);
    $create->handle(['team_id' => 13, 'thread_ref' => 'T-3', 'kind' => 'question', 'subject' => 'Other']);

    $results = $query->forTeam(12);
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});

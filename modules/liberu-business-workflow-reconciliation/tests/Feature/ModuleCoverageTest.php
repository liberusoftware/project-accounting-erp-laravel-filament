<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\CreateReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\StartWorkflowRun;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\TransitionReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\BusinessWorkflowReconciliationServiceProvider;
use Liberu\Platform\BusinessWorkflowReconciliation\Capability;
use Liberu\Platform\BusinessWorkflowReconciliation\Enums\LifecycleStatus;
use Liberu\Platform\BusinessWorkflowReconciliation\Enums\WorkflowRunState;
use Liberu\Platform\BusinessWorkflowReconciliation\Events\ReconciliationCaseTransitioned;
use Liberu\Platform\BusinessWorkflowReconciliation\Events\WorkflowRunStarted;
use Liberu\Platform\BusinessWorkflowReconciliation\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\WorkflowRun;

it('boots business workflow reconciliation provider and verifies capability and enums', function (): void {
    $provider = $this->app->register(BusinessWorkflowReconciliationServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $capability = app(Capability::class);

    expect($capability->name)->toBe('liberu-business-workflow-reconciliation')
        ->and($capability->supports('liberu.business-workflow-reconciliation'))->toBeTrue()
        ->and($capability->supports('liberu.business-workflow-reconciliation.lifecycle'))->toBeTrue()
        ->and($capability->supports('unknown.capability'))->toBeFalse()
        ->and(LifecycleStatus::Draft->allowedTransitions())->toBe([LifecycleStatus::Active, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Active->allowedTransitions())->toBe([LifecycleStatus::Completed, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Completed->allowedTransitions())->toBe([])
        ->and(LifecycleStatus::Archived->allowedTransitions())->toBe([])
        ->and(array_column(WorkflowRunState::cases(), 'value'))->toBe(['running', 'reconciled', 'needs_recovery', 'resolved']);
});

it('creates, queries, and transitions reconciliation cases and starts workflow runs', function (): void {
    Event::fake([ReconciliationCaseTransitioned::class, WorkflowRunStarted::class]);

    $case = app(CreateReconciliationCase::class)->execute([
        'tenant_id' => 'tenant-10',
        'idempotency_key' => 'recon-key-1',
        'name' => 'Month-End Bank Recon',
        'metadata' => ['period' => '2026-08'],
    ]);

    expect($case)->toBeInstanceOf(ReconciliationCase::class)
        ->and($case->status)->toBe('draft')
        ->and($case->metadata)->toBe(['period' => '2026-08'])
        ->and(ReconciliationCase::query()->forTenant('tenant-10')->count())->toBe(1)
        ->and(ReconciliationCase::query()->forTenant('tenant-99')->count())->toBe(0);

    $transition = app(TransitionReconciliationCase::class);
    $active = $transition->execute($case, LifecycleStatus::Active);
    expect($active->status)->toBe('active');

    $completed = $transition->execute($active, LifecycleStatus::Completed);
    expect($completed->status)->toBe('completed');

    expect(fn () => $transition->execute($completed, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class, 'Cannot transition reconciliation case from [completed] to [active].');

    $event = new ReconciliationCaseTransitioned((string) $case->getKey(), 'tenant-10', 'draft', 'active');
    expect($event->from)->toBe('draft')->and($event->to)->toBe('active');

    $startRun = app(StartWorkflowRun::class);
    $globalRun = $startRun->handle([
        'team_id' => null,
        'correlation_id' => 'corr-global-1',
        'workflow_key' => 'bank_reconcile',
        'contract_version' => '1.0',
        'steps' => ['fetch' => 'done'],
    ]);

    $teamRun = $startRun->handle([
        'team_id' => 7,
        'correlation_id' => 'corr-team-7',
        'workflow_key' => 'bank_reconcile',
        'contract_version' => '1.0',
    ]);

    expect($globalRun->state)->toBe(WorkflowRunState::Running)
        ->and($globalRun->steps)->toBe(['fetch' => 'done'])
        ->and($teamRun->steps)->toBe([])
        ->and(WorkflowRun::query()->forTeam(null)->count())->toBe(1)
        ->and(WorkflowRun::query()->forTeam(7)->count())->toBe(2)
        ->and(WorkflowRun::query()->forTeam(8)->count())->toBe(1);

    Event::assertDispatched(WorkflowRunStarted::class, fn (WorkflowRunStarted $e): bool => $e->run->is($globalRun));

    expect(fn () => $startRun->handle([
        'correlation_id' => 'corr-global-1',
        'workflow_key' => 'bank_reconcile',
        'contract_version' => '1.0',
    ]))->toThrow(ValidationException::class);
});

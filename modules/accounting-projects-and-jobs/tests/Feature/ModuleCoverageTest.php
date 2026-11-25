<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ProjectsAndJobs\Actions\CreateProjectJob;
use Liberu\Accounting\ProjectsAndJobs\Actions\TransitionProject;
use Liberu\Accounting\ProjectsAndJobs\Enums\ProjectStatus;
use Liberu\Accounting\ProjectsAndJobs\Exceptions\InvalidProject;
use Liberu\Accounting\ProjectsAndJobs\Models\ProjectCustomer;
use Liberu\Accounting\ProjectsAndJobs\Models\ProjectJob;
use Liberu\Accounting\ProjectsAndJobs\ProjectsAndJobsServiceProvider;

it('boots the service provider and loads projects and customers tables', function (): void {
    expect(app()->getProvider(ProjectsAndJobsServiceProvider::class))
        ->toBeInstanceOf(ProjectsAndJobsServiceProvider::class);

    expect(Schema::hasTable('accounting_project_customers'))->toBeTrue()
        ->and(Schema::hasTable('accounting_projects_and_jobs'))->toBeTrue();
});

it('exposes all project status enum cases', function (): void {
    expect(array_map(fn (ProjectStatus $c): string => $c->value, ProjectStatus::cases()))->toBe([
        'draft',
        'active',
        'on_hold',
        'completed',
        'cancelled',
        'archived',
    ]);
});

it('creates project customers, parent/child jobs, and validates inputs', function (): void {
    $customer = ProjectCustomer::create([
        'team_id' => 1,
        'customer_ref' => 'CUST-001',
        'name' => 'Acme Corp',
        'status' => 'active',
        'metadata' => ['tier' => 'enterprise'],
    ]);

    $create = app(CreateProjectJob::class);

    $parent = $create->handle([
        'team_id' => 1,
        'customer_id' => $customer->id,
        'name' => '  Core Implementation  ',
        'code' => 'PRJ-001',
        'description' => 'Main delivery',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'manager_ref' => 'pm@example.com',
        'budget_amount' => 50000.25,
        'budget_currency' => 'GBP',
        'dimensions' => ['cost_center' => 'CC-10'],
        'source_links' => ['crm' => 'OPP-1'],
        'metadata' => ['priority' => 'high'],
    ]);

    $child = $create->handle([
        'team_id' => 1,
        'customer_id' => $customer->id,
        'parent_id' => $parent->id,
        'name' => 'Phase 1 Discovery',
    ]);

    expect($parent->name)->toBe('Core Implementation')
        ->and($parent->status)->toBe(ProjectStatus::Draft)
        ->and((float) $parent->budget_amount)->toBe(50000.25)
        ->and($parent->dimensions)->toBe(['cost_center' => 'CC-10'])
        ->and($parent->source_links)->toBe(['crm' => 'OPP-1'])
        ->and($parent->metadata)->toBe(['priority' => 'high'])
        ->and($parent->customer->is($customer))->toBeTrue()
        ->and($customer->projects)->toHaveCount(2)
        ->and($child->parent->is($parent))->toBeTrue()
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->is($child))->toBeTrue();

    expect(fn () => $create->handle(['name' => '   ']))
        ->toThrow(InvalidProject::class, 'Name is required and end_date cannot precede start_date.');

    expect(fn () => $create->handle([
        'name' => 'Bad Dates',
        'start_date' => '2026-06-01',
        'end_date' => '2026-05-31',
    ]))->toThrow(InvalidProject::class, 'Name is required and end_date cannot precede start_date.');
});

it('enforces valid and invalid project status transitions', function (): void {
    $create = app(CreateProjectJob::class);
    $transition = app(TransitionProject::class);

    // Draft -> Active -> OnHold -> Active -> Completed -> Archived
    $project = $create->handle(['name' => 'Lifecycle Project']);
    expect($transition->handle($project, ProjectStatus::Active)->status)->toBe(ProjectStatus::Active);
    expect($transition->handle($project, ProjectStatus::OnHold)->status)->toBe(ProjectStatus::OnHold);
    expect($transition->handle($project, ProjectStatus::Active)->status)->toBe(ProjectStatus::Active);
    expect($transition->handle($project, ProjectStatus::Completed)->status)->toBe(ProjectStatus::Completed);
    expect($transition->handle($project, ProjectStatus::Archived)->status)->toBe(ProjectStatus::Archived);

    // Terminal state Archived rejects transitions
    expect(fn () => $transition->handle($project, ProjectStatus::Active))
        ->toThrow(InvalidProject::class, 'Cannot transition from archived to active.');

    // Draft -> Cancelled (and Cancelled is terminal)
    $cancelledFromDraft = $create->handle(['name' => 'Cancelled Draft']);
    expect($transition->handle($cancelledFromDraft, ProjectStatus::Cancelled)->status)->toBe(ProjectStatus::Cancelled);
    expect(fn () => $transition->handle($cancelledFromDraft, ProjectStatus::Active))
        ->toThrow(InvalidProject::class, 'Cannot transition from cancelled to active.');

    // Active -> Cancelled and OnHold -> Cancelled
    $activeCancel = $transition->handle($create->handle(['name' => 'Cancel Active']), ProjectStatus::Active);
    expect($transition->handle($activeCancel, ProjectStatus::Cancelled)->status)->toBe(ProjectStatus::Cancelled);

    $holdCancel = $transition->handle(
        $transition->handle($create->handle(['name' => 'Cancel Hold']), ProjectStatus::Active),
        ProjectStatus::OnHold,
    );
    expect($transition->handle($holdCancel, ProjectStatus::Cancelled)->status)->toBe(ProjectStatus::Cancelled);

    // Invalid shortcuts
    $draft = $create->handle(['name' => 'Invalid Shortcut']);
    expect(fn () => $transition->handle($draft, ProjectStatus::Completed))
        ->toThrow(InvalidProject::class, 'Cannot transition from draft to completed.');

    $completed = $transition->handle(
        $transition->handle($create->handle(['name' => 'Completed Invalid']), ProjectStatus::Active),
        ProjectStatus::Completed,
    );
    expect(fn () => $transition->handle($completed, ProjectStatus::Cancelled))
        ->toThrow(InvalidProject::class, 'Cannot transition from completed to cancelled.');
});

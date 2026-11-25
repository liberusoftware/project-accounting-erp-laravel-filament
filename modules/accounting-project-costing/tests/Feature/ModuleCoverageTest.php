<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ProjectCosting\Actions\RecordProjectCost;
use Liberu\Accounting\ProjectCosting\Enums\CostType;
use Liberu\Accounting\ProjectCosting\Exceptions\InvalidCost;
use Liberu\Accounting\ProjectCosting\Models\ProjectCost;
use Liberu\Accounting\ProjectCosting\ProjectCostingServiceProvider;
use Liberu\Accounting\ProjectCosting\Queries\ProjectCostSummary;
use Liberu\Accounting\ProjectsAndJobs\Actions\CreateProjectJob;

it('boots the service provider and loads the project costs table', function (): void {
    expect(app()->getProvider(ProjectCostingServiceProvider::class))
        ->toBeInstanceOf(ProjectCostingServiceProvider::class);

    expect(Schema::hasTable('accounting_project_costs'))->toBeTrue();
});

it('exposes all cost type enum cases', function (): void {
    expect(array_map(fn (CostType $c): string => $c->value, CostType::cases()))->toBe([
        'labor',
        'expense',
        'bill',
        'inventory',
        'subcontractor',
        'overhead',
    ]);
});

it('records and upserts project costs and validates negative amounts', function (): void {
    $project = app(CreateProjectJob::class)->handle(['name' => 'ERP Rollout']);
    $action = app(RecordProjectCost::class);

    $cost = $action->handle([
        'team_id' => 5,
        'project_job_id' => $project->id,
        'type' => 'labor',
        'occurred_on' => '2026-04-15',
        'amount' => 1250.75,
        'currency' => 'GBP',
        'committed' => true,
        'actual' => true,
        'wip_amount' => 300.25,
        'source_ref' => 'TS-101',
        'dimensions' => ['department' => 'engineering'],
        'metadata' => ['hours' => 12],
    ]);

    expect($cost)->toBeInstanceOf(ProjectCost::class)
        ->and($cost->type)->toBe(CostType::Labor)
        ->and((float) $cost->amount)->toBe(1250.75)
        ->and($cost->committed)->toBeTrue()
        ->and($cost->actual)->toBeTrue()
        ->and((float) $cost->wip_amount)->toBe(300.25)
        ->and($cost->dimensions)->toBe(['department' => 'engineering'])
        ->and($cost->metadata)->toBe(['hours' => 12])
        ->and($cost->projectJob->is($project))->toBeTrue();

    // Upsert same project_job_id, source_ref, type, team_id
    $updated = $action->handle([
        'team_id' => 5,
        'project_job_id' => $project->id,
        'type' => 'labor',
        'source_ref' => 'TS-101',
        'amount' => 1500.00,
    ]);

    expect($updated->id)->toBe($cost->id)
        ->and((float) $updated->amount)->toBe(1500.0);

    expect(fn () => $action->handle([
        'project_job_id' => $project->id,
        'type' => 'expense',
        'amount' => -1,
    ]))->toThrow(InvalidCost::class, 'Cost amounts cannot be negative.');
});

it('summarizes project costs across types including variance and cost-to-complete clamping', function (): void {
    $action = app(RecordProjectCost::class);
    $summary = app(ProjectCostSummary::class);

    expect($summary->forProject(888))->toBe([
        'total_cost' => 0.0,
        'committed' => 0.0,
        'actual' => 0.0,
        'wip' => 0.0,
        'cost_to_complete' => 0.0,
        'variance' => 0.0,
        'by_type' => [],
        'entries' => 0,
    ]);

    $action->handle([
        'team_id' => 1,
        'project_job_id' => 77,
        'type' => 'subcontractor',
        'amount' => 1000,
        'committed' => true,
        'actual' => false,
        'wip_amount' => 100,
        'source_ref' => 'PO-1',
    ]);

    $action->handle([
        'team_id' => 1,
        'project_job_id' => 77,
        'type' => 'expense',
        'amount' => 400,
        'committed' => false,
        'actual' => true,
        'wip_amount' => 50,
        'source_ref' => 'EXP-1',
    ]);

    $action->handle([
        'team_id' => 2,
        'project_job_id' => 77,
        'type' => 'overhead',
        'amount' => 900,
        'committed' => false,
        'actual' => true,
        'source_ref' => 'OVH-1',
    ]);

    expect($summary->forProject(77, 1))->toBe([
        'total_cost' => 1400.0,
        'committed' => 1000.0,
        'actual' => 400.0,
        'wip' => 150.0,
        'cost_to_complete' => 600.0,
        'variance' => 600.0,
        'by_type' => [
            'subcontractor' => 1000.0,
            'expense' => 400.0,
        ],
        'entries' => 2,
    ]);

    // When actual exceeds committed, cost_to_complete clamps to 0.0 and variance is negative
    $allTeams = $summary->forProject(77);
    expect($allTeams['committed'])->toBe(1000.0)
        ->and($allTeams['actual'])->toBe(1300.0)
        ->and($allTeams['cost_to_complete'])->toBe(0.0)
        ->and($allTeams['variance'])->toBe(-300.0)
        ->and($allTeams['entries'])->toBe(3);
});

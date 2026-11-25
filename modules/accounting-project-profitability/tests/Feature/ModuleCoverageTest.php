<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ProjectProfitability\Actions\FinalizeProjectProfitability;
use Liberu\Accounting\ProjectProfitability\Actions\RecordProjectProfitability;
use Liberu\Accounting\ProjectProfitability\Enums\ProfitabilityStatus;
use Liberu\Accounting\ProjectProfitability\Exceptions\InvalidProfitability;
use Liberu\Accounting\ProjectProfitability\Models\ProjectProfitability;
use Liberu\Accounting\ProjectProfitability\ProjectProfitabilityServiceProvider;
use Liberu\Accounting\ProjectProfitability\Queries\ProjectProfitabilityDashboard;
use Liberu\Accounting\ProjectsAndJobs\Actions\CreateProjectJob;

it('boots the service provider and loads the project profitability table', function (): void {
    expect(app()->getProvider(ProjectProfitabilityServiceProvider::class))
        ->toBeInstanceOf(ProjectProfitabilityServiceProvider::class);

    expect(Schema::hasTable('accounting_project_profitability'))->toBeTrue();
});

it('exposes profitability status enum cases', function (): void {
    expect(array_map(fn (ProfitabilityStatus $c): string => $c->value, ProfitabilityStatus::cases()))
        ->toBe(['draft', 'final', 'reversed']);
});

it('records, upserts, and calculates margins and realization on ProjectProfitability', function (): void {
    $project = app(CreateProjectJob::class)->handle(['name' => 'Audit Engagement']);
    $recordAction = app(RecordProjectProfitability::class);

    $record = $recordAction->handle([
        'team_id' => 4,
        'project_job_id' => $project->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'GBP',
        'revenue_amount' => 1000,
        'cost_amount' => 400,
        'estimate_amount' => 1200,
        'committed_amount' => 500,
        'actual_amount' => 400,
        'unbilled_wip_amount' => 200,
        'billed_amount' => 900,
        'dimensions' => ['region' => 'uk'],
        'source_links' => ['billing' => 1],
        'metadata' => ['note' => 'Jan close'],
    ]);

    expect($record)->toBeInstanceOf(ProjectProfitability::class)
        ->and($record->status)->toBe(ProfitabilityStatus::Draft)
        ->and($record->projectJob->is($project))->toBeTrue()
        ->and($record->marginAmount())->toBe(600.0)
        ->and($record->marginPercent())->toBe(60.0)
        ->and($record->realizationPercent())->toBe(75.0)
        ->and($record->dimensions)->toBe(['region' => 'uk'])
        ->and($record->source_links)->toBe(['billing' => 1])
        ->and($record->metadata)->toBe(['note' => 'Jan close']);

    // Upsert same project/period/team
    $updated = $recordAction->handle([
        'team_id' => 4,
        'project_job_id' => $project->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'revenue_amount' => 1200,
        'cost_amount' => 300,
    ]);

    expect($updated->id)->toBe($record->id)
        ->and($updated->marginAmount())->toBe(900.0);

    // Zero denominators return 0.0
    $zero = $recordAction->handle([
        'team_id' => 4,
        'project_job_id' => $project->id,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
    ]);

    expect($zero->marginPercent())->toBe(0.0)
        ->and($zero->realizationPercent())->toBe(0.0);
});

it('validates dates and non-negative monetary fields in RecordProjectProfitability', function (): void {
    $action = app(RecordProjectProfitability::class);

    expect(fn () => $action->handle([
        'project_job_id' => 1,
        'period_start' => '2026-05-10',
        'period_end' => '2026-05-01',
    ]))->toThrow(InvalidProfitability::class, 'The profitability period must end on or after it starts.');

    foreach (['revenue_amount', 'cost_amount', 'estimate_amount', 'committed_amount', 'actual_amount', 'unbilled_wip_amount', 'billed_amount'] as $field) {
        expect(fn () => $action->handle([
            'project_job_id' => 1,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            $field => -0.01,
        ]))->toThrow(InvalidProfitability::class, $field.' cannot be negative.');
    }
});

it('finalizes draft profitability records and rejects reversed records', function (): void {
    $recordAction = app(RecordProjectProfitability::class);
    $finalize = app(FinalizeProjectProfitability::class);

    $draft = $recordAction->handle([
        'project_job_id' => 15,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'revenue_amount' => 500,
    ]);

    $final = $finalize->handle($draft);
    expect($final->status)->toBe(ProfitabilityStatus::Final);

    $reversed = $recordAction->handle([
        'project_job_id' => 15,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'status' => ProfitabilityStatus::Reversed,
    ]);

    expect(fn () => $finalize->handle($reversed))
        ->toThrow(InvalidProfitability::class, 'A reversed profitability record cannot be finalized.');
});

it('builds project profitability dashboards for empty and populated projects', function (): void {
    $dashboard = app(ProjectProfitabilityDashboard::class);
    $recordAction = app(RecordProjectProfitability::class);

    expect($dashboard->forProject(999))->toBe([
        'revenue' => 0.0,
        'cost' => 0.0,
        'margin' => 0.0,
        'margin_percent' => 0.0,
        'estimate' => 0.0,
        'committed_actual' => ['committed' => 0.0, 'actual' => 0.0],
        'unbilled_wip' => 0.0,
        'realization_percent' => 0.0,
        'periods' => 0,
    ]);

    $recordAction->handle([
        'team_id' => 7,
        'project_job_id' => 25,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'revenue_amount' => 1000,
        'cost_amount' => 250,
        'estimate_amount' => 1000,
        'committed_amount' => 300,
        'actual_amount' => 250,
        'unbilled_wip_amount' => 100,
        'billed_amount' => 800,
    ]);

    $recordAction->handle([
        'team_id' => 7,
        'project_job_id' => 25,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'revenue_amount' => 1000,
        'cost_amount' => 750,
        'estimate_amount' => 1000,
        'committed_amount' => 800,
        'actual_amount' => 750,
        'unbilled_wip_amount' => 50,
        'billed_amount' => 700,
    ]);

    $recordAction->handle([
        'team_id' => 8,
        'project_job_id' => 25,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'revenue_amount' => 5000,
    ]);

    expect($dashboard->forProject(25, 7))->toBe([
        'revenue' => 2000.0,
        'cost' => 1000.0,
        'margin' => 1000.0,
        'margin_percent' => 50.0,
        'estimate' => 2000.0,
        'committed_actual' => ['committed' => 1100.0, 'actual' => 1000.0],
        'unbilled_wip' => 150.0,
        'realization_percent' => 75.0,
        'periods' => 2,
    ]);

    expect($dashboard->forProject(25)['periods'])->toBe(3);
});

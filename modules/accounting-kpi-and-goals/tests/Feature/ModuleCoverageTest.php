<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\KpiAndGoals\Actions\AddCommentary;
use Liberu\Accounting\KpiAndGoals\Actions\CreateGoal;
use Liberu\Accounting\KpiAndGoals\Actions\CreateMetric;
use Liberu\Accounting\KpiAndGoals\Actions\RecordMeasurement;
use Liberu\Accounting\KpiAndGoals\Enums\AlertStatus;
use Liberu\Accounting\KpiAndGoals\Enums\GoalStatus;
use Liberu\Accounting\KpiAndGoals\Events\KpiMeasurementRecorded;
use Liberu\Accounting\KpiAndGoals\Exceptions\InvalidKpi;
use Liberu\Accounting\KpiAndGoals\KpiAndGoalsServiceProvider;
use Liberu\Accounting\KpiAndGoals\Models\KpiAlert;
use Liberu\Accounting\KpiAndGoals\Models\KpiCommentary;
use Liberu\Accounting\KpiAndGoals\Models\KpiGoal;
use Liberu\Accounting\KpiAndGoals\Models\KpiMeasurement;
use Liberu\Accounting\KpiAndGoals\Models\KpiMetric;
use Liberu\Accounting\KpiAndGoals\Queries\KpiQuery;

it('boots kpi and goals service provider and covers enums, exception, and event', function (): void {
    $provider = $this->app->register(KpiAndGoalsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(KpiAndGoalsServiceProvider::class)
        ->and(app(KpiQuery::class))->toBe(app(KpiQuery::class))
        ->and(array_column(AlertStatus::cases(), 'value'))->toBe(['open', 'acknowledged', 'resolved'])
        ->and(array_column(GoalStatus::cases(), 'value'))->toBe(['draft', 'active', 'achieved', 'at_risk', 'closed'])
        ->and((new InvalidKpi('KPI error'))->getMessage())->toBe('KPI error');

    Event::fake([KpiMeasurementRecorded::class]);
    $metric = app(CreateMetric::class)->handle([
        'team_id' => 1,
        'metric_ref' => 'MET-EVT-1',
        'name' => 'Gross Margin',
        'unit' => '%',
        'source_contract' => 'gl.margin',
    ]);
    $goal = app(CreateGoal::class)->handle($metric, [
        'goal_ref' => 'GOAL-EVT-1',
        'name' => 'Q1 Margin',
        'owner_ref' => 'cfo',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'target' => 50,
    ]);
    $measurement = app(RecordMeasurement::class)->handle($goal, [
        'period_ref' => '2026-01',
        'measured_on' => '2026-01-31',
        'value' => 55,
        'source_ref' => 'RPT-1',
    ]);
    $event = new KpiMeasurementRecorded($measurement);
    event($event);

    expect($event->measurement->is($measurement))->toBeTrue();
    Event::assertDispatched(KpiMeasurementRecorded::class);
});

it('executes metric creation, goals, measurements, alerts, commentary, relations, and queries', function (): void {
    $query = app(KpiQuery::class);
    expect($query->goals(8)->total())->toBe(0);

    $metric = app(CreateMetric::class)->handle([
        'team_id' => 8,
        'metric_ref' => 'MET-REV',
        'name' => 'Monthly Recurring Revenue',
        'description' => 'Recognized MRR',
        'unit' => 'USD',
        'direction' => 'higher',
        'source_contract' => 'billing.mrr',
        'formula' => 'sum(active_subscriptions)',
        'owner_ref' => 'vp-finance',
        'active' => true,
        'metadata' => ['board' => true],
    ]);

    expect($metric)->toBeInstanceOf(KpiMetric::class)
        ->and($metric->active)->toBeTrue()
        ->and($metric->metadata)->toBe(['board' => true]);

    $goal = app(CreateGoal::class)->handle($metric, [
        'goal_ref' => 'GOAL-MRR-2026',
        'name' => 'Reach 100k MRR',
        'owner_ref' => 'vp-finance',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'baseline' => 50,
        'target' => 100,
        'warning_threshold' => 70,
        'critical_threshold' => 60,
        'metadata' => ['quarter' => 'FY26'],
    ]);

    expect($goal)->toBeInstanceOf(KpiGoal::class)
        ->and($goal->status)->toBe(GoalStatus::Active)
        ->and($goal->metric->is($metric))->toBeTrue()
        ->and($metric->goals()->count())->toBe(1);

    // Progress before any measurements
    expect($query->progress($goal))->toBe([
        'goal_ref' => 'GOAL-MRR-2026',
        'target' => 100.0,
        'latest_value' => null,
        'progress' => 0,
        'status' => 'active',
        'open_alerts' => 0,
    ]);

    $record = app(RecordMeasurement::class);

    // Critical threshold breach -> AtRisk + critical alert
    $mCritical = $record->handle($goal, [
        'period_ref' => '2026-01',
        'measured_on' => '2026-01-31',
        'value' => 55,
        'source_ref' => 'SNAP-01',
        'metadata' => ['verified' => true],
    ]);
    $goal->refresh();
    expect($mCritical)->toBeInstanceOf(KpiMeasurement::class)
        ->and($mCritical->goal->is($goal))->toBeTrue()
        ->and($mCritical->metric->is($metric))->toBeTrue()
        ->and($goal->status)->toBe(GoalStatus::AtRisk)
        ->and($goal->alerts()->count())->toBe(1);

    $alert = $goal->alerts()->first();
    expect($alert)->toBeInstanceOf(KpiAlert::class)
        ->and($alert->severity)->toBe('critical')
        ->and($alert->status)->toBe(AlertStatus::Open)
        ->and($alert->goal->is($goal))->toBeTrue();

    // Warning threshold breach -> Active + warning alert
    $record->handle($goal, [
        'period_ref' => '2026-02',
        'measured_on' => '2026-02-28',
        'value' => 65,
        'source_ref' => 'SNAP-02',
    ]);
    $goal->refresh();
    expect($goal->status)->toBe(GoalStatus::Active)
        ->and($goal->alerts()->count())->toBe(2);

    // Target achieved -> Achieved, no new alert
    $record->handle($goal, [
        'period_ref' => '2026-03',
        'measured_on' => '2026-03-31',
        'value' => 110,
        'source_ref' => 'SNAP-03',
    ]);
    $goal->refresh();
    expect($goal->status)->toBe(GoalStatus::Achieved)
        ->and($goal->alerts()->count())->toBe(2)
        ->and($goal->measurements()->count())->toBe(3)
        ->and($metric->measurements()->count())->toBe(3);

    // Add commentary
    $comment = app(AddCommentary::class)->handle($goal, 'vp-finance', 'Exceeded target via enterprise expansion', '2026-03');
    expect($comment)->toBeInstanceOf(KpiCommentary::class)
        ->and($comment->goal->is($goal))->toBeTrue()
        ->and($goal->commentary()->count())->toBe(1);

    // Progress after measurements
    expect($query->progress($goal))->toBe([
        'goal_ref' => 'GOAL-MRR-2026',
        'target' => 100.0,
        'latest_value' => 110.0,
        'progress' => 1.0,
        'status' => 'achieved',
        'open_alerts' => 2,
    ]);

    // Equal baseline and target edge cases ($value === $target => 1, $value !== $target => 0)
    $flatGoal = app(CreateGoal::class)->handle($metric, [
        'goal_ref' => 'GOAL-FLAT',
        'name' => 'Maintain 0 defects',
        'owner_ref' => 'ops',
        'period_start' => '2026-01-01',
        'period_end' => '2026-06-30',
        'baseline' => 10,
        'target' => 10,
    ]);
    $flatMiss = $record->handle($flatGoal, [
        'period_ref' => '2026-01',
        'measured_on' => '2026-01-31',
        'value' => 5,
        'source_ref' => 'SNAP-FLAT-1',
    ]);
    expect((float) $flatMiss->progress)->toBe(0.0);

    $flatHit = $record->handle($flatGoal, [
        'period_ref' => '2026-02',
        'measured_on' => '2026-02-28',
        'value' => 10,
        'source_ref' => 'SNAP-FLAT-2',
    ]);
    expect((float) $flatHit->progress)->toBe(1.0);

    // Query filtering and pagination clamping
    $achievedPage = $query->goals(8, 'achieved', 200);
    expect($achievedPage->total())->toBe(2)
        ->and($achievedPage->perPage())->toBe(100);

    $emptyPage = $query->goals(8, 'closed', 0);
    expect($emptyPage->total())->toBe(0)
        ->and($emptyPage->perPage())->toBe(1);
});

it('enforces validation rules across kpi and goal actions', function (): void {
    $createMetric = app(CreateMetric::class);
    $validMetric = [
        'metric_ref' => 'MET-VAL',
        'name' => 'DSO',
        'unit' => 'days',
        'direction' => 'lower',
        'source_contract' => 'ar.dso',
    ];

    foreach (['metric_ref', 'name', 'unit', 'source_contract'] as $field) {
        expect(fn () => $createMetric->handle(array_diff_key($validMetric, [$field => true])))
            ->toThrow(InvalidKpi::class, 'Metric requires reference, name, unit, and source contract.');
    }

    expect(fn () => $createMetric->handle([...$validMetric, 'direction' => 'sideways']))
        ->toThrow(InvalidKpi::class, 'Metric direction must be higher or lower.');

    $metric = $createMetric->handle($validMetric);

    $createGoal = app(CreateGoal::class);
    $validGoal = [
        'goal_ref' => 'GOAL-VAL',
        'name' => 'Reduce DSO',
        'owner_ref' => 'ar-lead',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'target' => 30,
    ];

    foreach (['goal_ref', 'name', 'owner_ref', 'period_start', 'period_end', 'target'] as $field) {
        expect(fn () => $createGoal->handle($metric, array_diff_key($validGoal, [$field => true])))
            ->toThrow(InvalidKpi::class, 'Goal requires reference, name, owner, period, and target.');
    }

    expect(fn () => $createGoal->handle($metric, [...$validGoal, 'period_end' => '2025-12-31']))
        ->toThrow(InvalidKpi::class, 'Goal period end must not precede its start.');

    $goal = $createGoal->handle($metric, $validGoal);

    $record = app(RecordMeasurement::class);
    $validMeasurement = [
        'period_ref' => '2026-01',
        'measured_on' => '2026-01-31',
        'value' => 28,
        'source_ref' => 'AR-01',
    ];

    foreach (['period_ref', 'measured_on', 'value', 'source_ref'] as $field) {
        expect(fn () => $record->handle($goal, array_diff_key($validMeasurement, [$field => true])))
            ->toThrow(InvalidKpi::class, 'Measurement requires period, finite value, date, and source reference.');
    }

    expect(fn () => app(AddCommentary::class)->handle($goal, 'ar-lead', '   '))
        ->toThrow(InvalidKpi::class, 'Commentary body is required.');
});

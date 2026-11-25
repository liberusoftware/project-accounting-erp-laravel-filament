<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\Platform\ExecutiveInsights\Actions\CreateInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Actions\RegisterMetric;
use Liberu\Platform\ExecutiveInsights\Actions\TransitionInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Capability;
use Liberu\Platform\ExecutiveInsights\Enums\LifecycleStatus;
use Liberu\Platform\ExecutiveInsights\Events\InsightSnapshotTransitioned;
use Liberu\Platform\ExecutiveInsights\Events\MetricRegistered;
use Liberu\Platform\ExecutiveInsights\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\ExecutiveInsights\ExecutiveInsightsServiceProvider;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Models\MetricDefinition;

it('boots executive insights provider and verifies capability and lifecycle enum', function (): void {
    $provider = $this->app->register(ExecutiveInsightsServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $capability = app(Capability::class);

    expect($capability->name)->toBe('liberu-executive-insights')
        ->and($capability->supports('liberu.executive-insights'))->toBeTrue()
        ->and($capability->supports('liberu.executive-insights.lifecycle'))->toBeTrue()
        ->and($capability->supports('nope'))->toBeFalse()
        ->and(LifecycleStatus::Draft->allowedTransitions())->toBe([LifecycleStatus::Active, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Active->allowedTransitions())->toBe([LifecycleStatus::Completed, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Completed->allowedTransitions())->toBe([])
        ->and(LifecycleStatus::Archived->allowedTransitions())->toBe([]);
});

it('creates and transitions insight snapshots and registers metric definitions with validation', function (): void {
    Event::fake([InsightSnapshotTransitioned::class, MetricRegistered::class]);

    $snapshot = app(CreateInsightSnapshot::class)->execute([
        'tenant_id' => 'tenant-22',
        'idempotency_key' => 'snap-1',
        'name' => 'Q3 Executive Summary',
        'metadata' => ['quarter' => 'Q3'],
    ]);

    expect($snapshot)->toBeInstanceOf(InsightSnapshot::class)
        ->and($snapshot->status)->toBe('draft')
        ->and($snapshot->metadata)->toBe(['quarter' => 'Q3'])
        ->and(InsightSnapshot::query()->forTenant('tenant-22')->count())->toBe(1)
        ->and(InsightSnapshot::query()->forTenant('tenant-99')->count())->toBe(0);

    $transition = app(TransitionInsightSnapshot::class);
    $active = $transition->execute($snapshot, LifecycleStatus::Active);
    expect($active->status)->toBe('active');

    $archived = $transition->execute($active, LifecycleStatus::Archived);
    expect($archived->status)->toBe('archived');

    expect(fn () => $transition->execute($archived, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class, 'Cannot transition insight snapshot from [archived] to [active].');

    $ev = new InsightSnapshotTransitioned((string) $snapshot->getKey(), 'tenant-22', 'draft', 'active');
    expect($ev->recordId)->toBe((string) $snapshot->getKey());

    $registerMetric = app(RegisterMetric::class);

    expect(fn () => $registerMetric->handle([
        'key' => 'gross_margin',
        'name' => 'Gross Margin',
        'formula' => '(revenue - cogs) / revenue',
        'version' => '1.0',
        'currency' => 'gbp',
        'timezone' => 'UTC',
        'freshness_seconds' => 300,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $registerMetric->handle([
        'key' => 'gross_margin',
        'name' => 'Gross Margin',
        'formula' => '(revenue - cogs) / revenue',
        'version' => '1.0',
        'currency' => 'GBP',
        'timezone' => 'UTC',
        'freshness_seconds' => 0,
    ]))->toThrow(ValidationException::class);

    $globalMetric = $registerMetric->handle([
        'team_id' => null,
        'key' => 'mrr',
        'name' => 'Monthly Recurring Revenue',
        'formula' => 'sum(subscriptions.mrr)',
        'version' => '1.0',
        'dimensions' => ['region' => 'EMEA'],
        'currency' => 'GBP',
        'timezone' => 'UTC',
        'freshness_seconds' => 600,
    ]);

    $teamMetric = $registerMetric->handle([
        'team_id' => 5,
        'key' => 'arr',
        'name' => 'Annual Recurring Revenue',
        'formula' => 'mrr * 12',
        'version' => '1.0',
        'currency' => 'USD',
        'timezone' => 'UTC',
        'freshness_seconds' => 3600,
    ]);

    expect($globalMetric)->toBeInstanceOf(MetricDefinition::class)
        ->and($globalMetric->is_active)->toBeTrue()
        ->and($globalMetric->freshness_seconds)->toBe(600)
        ->and($globalMetric->dimensions)->toBe(['region' => 'EMEA'])
        ->and($teamMetric->dimensions)->toBe([])
        ->and(MetricDefinition::query()->forTeam(null)->count())->toBe(1)
        ->and(MetricDefinition::query()->forTeam(5)->count())->toBe(2)
        ->and(MetricDefinition::query()->forTeam(9)->count())->toBe(1);

    Event::assertDispatched(MetricRegistered::class, fn (MetricRegistered $e): bool => $e->metric->is($globalMetric));
});

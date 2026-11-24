<?php

declare(strict_types=1);

use Liberu\Accounting\BusinessInsights\Actions\RecordInsight;
use Liberu\Accounting\BusinessInsights\BusinessInsightsServiceProvider;
use Liberu\Accounting\BusinessInsights\Models\InsightSnapshot;
use Liberu\Accounting\BusinessInsights\Queries\InsightQuery;

it('boots business insights service provider', function (): void {
    $provider = $this->app->register(BusinessInsightsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BusinessInsightsServiceProvider::class);
});

it('records, updates, queries, and validates business insight snapshots', function (): void {
    $query = app(InsightQuery::class);
    $record = app(RecordInsight::class);

    expect($query->paginate(3)->total())->toBe(0);

    $snapshot = $record->handle([
        'team_id' => 3,
        'metric' => 'gross_margin',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'value' => 42.125,
        'comparison_value' => 39.5,
        'unit' => 'percent',
        'explanation' => 'Improved vendor pricing.',
        'metadata' => ['segment' => 'retail'],
    ]);

    expect($snapshot)->toBeInstanceOf(InsightSnapshot::class)
        ->and($snapshot->period_start->toDateString())->toBe('2026-01-01')
        ->and($snapshot->period_end->toDateString())->toBe('2026-01-31')
        ->and((float) $snapshot->value)->toBe(42.125)
        ->and((float) $snapshot->comparison_value)->toBe(39.5)
        ->and($snapshot->metadata)->toBe(['segment' => 'retail'])
        ->and($snapshot->refreshed_at)->not->toBeNull();

    // Update existing snapshot for same (team_id, metric, period_start, period_end)
    $updated = $record->handle([
        'team_id' => 3,
        'metric' => 'gross_margin',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'value' => 0,
        'explanation' => 'Recomputed zero margin',
    ]);

    expect($updated->getKey())->toBe($snapshot->getKey())
        ->and((float) $updated->value)->toBe(0.0)
        ->and($updated->explanation)->toBe('Recomputed zero margin');

    $newer = $record->handle([
        'team_id' => 3,
        'metric' => 'burn_rate',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'value' => 15000.25,
    ]);

    $allForTeam = $query->paginate(3, null, 200);
    expect($allForTeam->total())->toBe(2)
        ->and($allForTeam->perPage())->toBe(100)
        ->and($allForTeam->items()[0]->is($newer))->toBeTrue();

    $filtered = $query->paginate(3, 'gross_margin', 0);
    expect($filtered->total())->toBe(1)
        ->and($filtered->perPage())->toBe(1)
        ->and($filtered->items()[0]->is($updated))->toBeTrue();

    $valid = [
        'team_id' => 3,
        'metric' => 'cash_runway',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'value' => 18,
    ];

    foreach (['team_id', 'metric', 'period_start', 'period_end', 'value'] as $field) {
        expect(fn () => $record->handle(array_diff_key($valid, [$field => true])))
            ->toThrow(InvalidArgumentException::class, "{$field} is required.");
    }
});

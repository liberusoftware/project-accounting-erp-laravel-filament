<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\OperationalReports\Actions\GenerateReport;
use Liberu\Accounting\OperationalReports\Actions\PublishReport;
use Liberu\Accounting\OperationalReports\Enums\ReportCategory;
use Liberu\Accounting\OperationalReports\Enums\ReportRunStatus;
use Liberu\Accounting\OperationalReports\Events\ReportGenerated;
use Liberu\Accounting\OperationalReports\Events\ReportPublished;
use Liberu\Accounting\OperationalReports\Exceptions\InvalidReport;
use Liberu\Accounting\OperationalReports\Models\ReportAudit;
use Liberu\Accounting\OperationalReports\Models\ReportException;
use Liberu\Accounting\OperationalReports\Models\ReportRow;
use Liberu\Accounting\OperationalReports\Models\ReportRun;
use Liberu\Accounting\OperationalReports\OperationalReportsServiceProvider;
use Liberu\Accounting\OperationalReports\Queries\ReportQuery;

it('registers and boots the operational reports service provider', function (): void {
    $provider = $this->app->register(OperationalReportsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(OperationalReportsServiceProvider::class);
});

it('exposes all operational report enum cases', function (): void {
    expect(array_map(fn (ReportCategory $c): string => $c->value, ReportCategory::cases()))
        ->toBe(['inventory', 'assets', 'expenses', 'projects', 'payroll', 'exceptions']);

    expect(array_map(fn (ReportRunStatus $s): string => $s->value, ReportRunStatus::cases()))
        ->toBe(['draft', 'running', 'ready', 'published', 'failed', 'archived']);
});

it('validates inputs and generates operational reports idempotently with rows, exceptions, audits, and event', function (): void {
    Event::fake([ReportGenerated::class]);
    $action = new GenerateReport();

    expect(fn () => $action->handle([
        'name' => 'Missing key',
        'category' => 'inventory',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]))->toThrow(InvalidReport::class, 'Report field [report_key] is required.');

    expect(fn () => $action->handle([
        'report_key' => 'inv-1',
        'name' => 'Bad category',
        'category' => 'unknown',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]))->toThrow(InvalidReport::class, 'A supported report category is required.');

    expect(fn () => $action->handle([
        'report_key' => 'inv-1',
        'name' => 'Bad row',
        'category' => 'inventory',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ], [['row_key' => '   ']]))->toThrow(InvalidReport::class, 'Every report row requires a stable row_key.');

    expect(fn () => $action->handle([
        'report_key' => 'inv-1',
        'name' => 'Bad exception',
        'category' => 'inventory',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ], [['row_key' => 'r-1', 'amount' => 10]], [['exception_key' => 'ex-1']]))
        ->toThrow(InvalidReport::class, 'Report exceptions require a key and message.');

    $attributes = [
        'team_id' => 10,
        'report_key' => 'inventory-valuation',
        'name' => 'Inventory Valuation Jan 2026',
        'category' => 'inventory',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'gbp',
        'filters' => ['warehouse' => 'LON'],
        'requested_by' => 7,
        'metadata' => ['source' => 'nightly'],
    ];
    $rows = [
        ['row_key' => 'sku-1', 'label' => 'Widget A', 'amount' => 125.50, 'state' => 'ok', 'dimensions' => ['dept' => 'ops']],
        ['row_key' => 'sku-2', 'amount' => 74.50, 'currency' => 'USD', 'payload' => ['custom' => true]],
    ];
    $exceptions = [
        ['exception_key' => 'missing-cost', 'severity' => 'warning', 'message' => 'Cost missing for SKU-3', 'source_type' => 'sku', 'source_id' => '3'],
    ];

    $run = $action->handle($attributes, $rows, $exceptions);

    expect($run)->toBeInstanceOf(ReportRun::class)
        ->and($run->category)->toBe(ReportCategory::Inventory)
        ->and($run->status)->toBe(ReportRunStatus::Ready)
        ->and($run->currency)->toBe('GBP')
        ->and($run->summary)->toBe(['row_count' => 2, 'exception_count' => 1, 'total_amount' => 200])
        ->and($run->rows)->toHaveCount(2)
        ->and($run->exceptions)->toHaveCount(1)
        ->and($run->audits)->toHaveCount(1);

    $firstRow = $run->rows->first();
    $firstException = $run->exceptions->first();
    $firstAudit = $run->audits->first();

    expect($firstRow)->toBeInstanceOf(ReportRow::class)
        ->and($firstRow->run->is($run))->toBeTrue()
        ->and($firstException)->toBeInstanceOf(ReportException::class)
        ->and($firstException->run->is($run))->toBeTrue()
        ->and($firstAudit)->toBeInstanceOf(ReportAudit::class)
        ->and($firstAudit->run->is($run))->toBeTrue();

    Event::assertDispatched(ReportGenerated::class, fn (ReportGenerated $event): bool => $event->run->is($run));

    // Idempotent duplicate returns existing run without creating a new row
    $duplicate = $action->handle($attributes, $rows, $exceptions);
    expect($duplicate->id)->toBe($run->id)
        ->and(ReportRun::query()->count())->toBe(1);
});

it('publishes ready reports and blocks invalid status or open blocking exceptions', function (): void {
    Event::fake([ReportPublished::class]);
    $generate = new GenerateReport();
    $publish = new PublishReport();

    $draftRun = ReportRun::create([
        'team_id' => 1,
        'report_key' => 'draft-key',
        'name' => 'Draft Run',
        'category' => ReportCategory::Expenses,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'status' => ReportRunStatus::Draft,
        'source_hash' => str_repeat('a', 64),
    ]);

    expect(fn () => $publish->handle($draftRun, 5))
        ->toThrow(InvalidReport::class, 'Only a ready report can be published.');

    $blockedRun = $generate->handle([
        'team_id' => 1,
        'report_key' => 'blocked-key',
        'name' => 'Blocked Report',
        'category' => 'payroll',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
    ], [], [
        ['exception_key' => 'blk-1', 'severity' => 'blocking', 'message' => 'Unbalanced payroll'],
    ]);

    expect(fn () => $publish->handle($blockedRun, 5))
        ->toThrow(InvalidReport::class, 'Blocking report exceptions must be resolved before publication.');

    $blockedRun->exceptions()->update(['status' => 'resolved', 'resolved_by' => 5, 'resolved_at' => now()]);

    $published = $publish->handle($blockedRun, 5);
    expect($published->status)->toBe(ReportRunStatus::Published)
        ->and($published->published_by)->toBe(5)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->audits()->where('event_type', 'report_published')->exists())->toBeTrue();

    // Already published report can be republished
    $republished = $publish->handle($published, 6);
    expect($republished->published_by)->toBe(6);

    Event::assertDispatched(ReportPublished::class, 2);
});

it('queries operational report runs and open exceptions with filters', function (): void {
    $query = new ReportQuery();
    expect($query->paginate()->total())->toBe(0)
        ->and($query->exceptions()->total())->toBe(0);

    $generate = new GenerateReport();
    $run1 = $generate->handle([
        'team_id' => 11,
        'report_key' => 'r1',
        'name' => 'Assets Q1',
        'category' => 'assets',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ], [['row_key' => 'a1', 'amount' => 50]], [
        ['exception_key' => 'e1', 'message' => 'Minor issue'],
    ]);

    $run2 = $generate->handle([
        'team_id' => 22,
        'report_key' => 'r2',
        'name' => 'Projects Q1',
        'category' => 'projects',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ]);
    (new PublishReport())->handle($run2, 1);

    expect($query->paginate(teamId: 11)->total())->toBe(1)
        ->and($query->paginate(category: 'projects', status: 'published')->total())->toBe(1)
        ->and($query->paginate(perPage: 500)->perPage())->toBe(100)
        ->and($query->paginate(perPage: 0)->perPage())->toBe(1)
        ->and($query->exceptions(teamId: 11)->items()[0]->id)->toBe($run1->id)
        ->and($query->exceptions(teamId: 22)->total())->toBe(0);
});

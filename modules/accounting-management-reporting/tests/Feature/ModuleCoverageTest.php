<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ManagementReporting\Actions\AddReportContent;
use Liberu\Accounting\ManagementReporting\Actions\ArchiveReport;
use Liberu\Accounting\ManagementReporting\Actions\CreateReportPack;
use Liberu\Accounting\ManagementReporting\Actions\DeliverReport;
use Liberu\Accounting\ManagementReporting\Actions\ReviewReport;
use Liberu\Accounting\ManagementReporting\Enums\ReportStatus;
use Liberu\Accounting\ManagementReporting\Enums\ReviewDecision;
use Liberu\Accounting\ManagementReporting\Events\ReportApproved;
use Liberu\Accounting\ManagementReporting\Exceptions\InvalidReport;
use Liberu\Accounting\ManagementReporting\ManagementReportingServiceProvider;
use Liberu\Accounting\ManagementReporting\Models\ReportChart;
use Liberu\Accounting\ManagementReporting\Models\ReportDelivery;
use Liberu\Accounting\ManagementReporting\Models\ReportNarrative;
use Liberu\Accounting\ManagementReporting\Models\ReportPack;
use Liberu\Accounting\ManagementReporting\Models\ReportReview;
use Liberu\Accounting\ManagementReporting\Models\ReportSchedule;
use Liberu\Accounting\ManagementReporting\Queries\ReportQuery;

it('registers the service provider, singleton query, and migrations', function (): void {
    expect(app()->getProviders(ManagementReportingServiceProvider::class))->not->toBeEmpty()
        ->and(app(ReportQuery::class))->toBe(app(ReportQuery::class))
        ->and(Schema::hasTable('accounting_management_report_packs'))->toBeTrue()
        ->and(Schema::hasTable('accounting_management_report_narratives'))->toBeTrue()
        ->and(Schema::hasTable('accounting_management_report_charts'))->toBeTrue()
        ->and(Schema::hasTable('accounting_management_report_schedules'))->toBeTrue()
        ->and(Schema::hasTable('accounting_management_report_reviews'))->toBeTrue()
        ->and(Schema::hasTable('accounting_management_report_deliveries'))->toBeTrue();
});

it('exposes report enums and exception', function (): void {
    expect(array_map(fn (ReportStatus $s) => $s->value, ReportStatus::cases()))
        ->toBe(['draft', 'in_review', 'approved', 'delivered', 'archived', 'failed'])
        ->and(array_map(fn (ReviewDecision $d) => $d->value, ReviewDecision::cases()))
        ->toBe(['approved', 'rejected', 'requested']);

    $exception = new InvalidReport('Invalid report');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Invalid report');
});

it('runs full report pack lifecycle: create, content, schedule, review transitions, deliver, and archive', function (): void {
    $createPack = new CreateReportPack();
    $addContent = new AddReportContent();
    $reviewReport = new ReviewReport();
    $deliverReport = new DeliverReport();
    $archiveReport = new ArchiveReport();

    $pack = $createPack->handle([
        'team_id' => 5,
        'report_ref' => 'RPT-Q1',
        'name' => 'Q1 Board Pack',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'currency' => 'gbp',
        'owner_ref' => 'cfo@example.com',
        'metadata' => ['board' => true],
    ]);

    expect($pack)->toBeInstanceOf(ReportPack::class)
        ->and($pack->currency)->toBe('GBP')
        ->and($pack->status)->toBe(ReportStatus::Draft)
        ->and($pack->version)->toBe(1);

    $narrative = $addContent->narrative($pack, [
        'section_ref' => 'exec-summary',
        'title' => 'Executive Summary',
        'body' => 'Revenue grew 15%.',
        'author_ref' => 'analyst-1',
    ]);
    $chart = $addContent->chart($pack, [
        'chart_ref' => 'rev-trend',
        'title' => 'Revenue Trend',
        'chart_type' => 'line',
        'series' => [['name' => 'Revenue', 'data' => [100, 120, 130]]],
        'options' => ['stacked' => false],
    ]);
    $schedule = ReportSchedule::create([
        'report_pack_id' => $pack->id,
        'frequency' => 'monthly',
        'timezone' => 'UTC',
        'recipients' => ['board@example.com'],
        'next_run_at' => now()->addDay(),
        'active' => true,
    ]);

    expect($narrative)->toBeInstanceOf(ReportNarrative::class)
        ->and($narrative->reportPack->is($pack))->toBeTrue()
        ->and($chart)->toBeInstanceOf(ReportChart::class)
        ->and($chart->data_source)->toBe('module-contract')
        ->and($chart->reportPack->is($pack))->toBeTrue()
        ->and($schedule)->toBeInstanceOf(ReportSchedule::class)
        ->and($schedule->active)->toBeTrue()
        ->and($schedule->reportPack->is($pack))->toBeTrue()
        ->and($pack->schedules()->count())->toBe(1);

    // Request review -> InReview
    $inReview = $reviewReport->handle($pack, 'reviewer-1', 'requested', 'Please review');
    expect($inReview->status)->toBe(ReportStatus::InReview)
        ->and($inReview->approved_by)->toBeNull();

    // Reject -> back to Draft
    $rejected = $reviewReport->handle($inReview, 'reviewer-1', 'rejected', 'Fix figures');
    expect($rejected->status)->toBe(ReportStatus::Draft)
        ->and($rejected->reviews()->count())->toBe(2)
        ->and($rejected->reviews->first())->toBeInstanceOf(ReportReview::class)
        ->and($rejected->reviews->first()->reportPack->is($rejected))->toBeTrue();

    // Approve -> Approved
    $approved = $reviewReport->handle($rejected, 'cfo', 'approved', 'Looks good');
    expect($approved->status)->toBe(ReportStatus::Approved)
        ->and($approved->approved_by)->toBe('cfo')
        ->and($approved->approved_at)->not->toBeNull();

    // Deliver as pdf
    $delivery = $deliverReport->handle($approved, 'pdf', [
        'file_ref' => 's3://packs/q1.pdf',
        'recipients' => ['board@example.com'],
        'checksum' => 'abc123',
    ]);
    $approved->refresh();

    expect($delivery)->toBeInstanceOf(ReportDelivery::class)
        ->and($delivery->reportPack->is($approved))->toBeTrue()
        ->and($delivery->status)->toBe('delivered')
        ->and($approved->status)->toBe(ReportStatus::Delivered)
        ->and($approved->delivered_at)->not->toBeNull();

    // Archive from Delivered
    $archived = $archiveReport->handle($approved);
    expect($archived->status)->toBe(ReportStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull();

    // Also test archiving directly from Approved
    $pack2 = $createPack->handle([
        'team_id' => 5,
        'report_ref' => 'RPT-Q2',
        'name' => 'Q2 Pack',
        'period_start' => '2026-04-01',
        'period_end' => '2026-06-30',
        'currency' => 'USD',
    ]);
    $approved2 = $reviewReport->handle($pack2, 'cfo', 'approved');
    $archived2 = $archiveReport->handle($approved2);
    expect($archived2->status)->toBe(ReportStatus::Archived);

    // Event dispatch check
    Event::fake([ReportApproved::class]);
    ReportApproved::dispatch($approved2);
    Event::assertDispatched(ReportApproved::class, fn (ReportApproved $e) => $e->report->is($approved2));
});

it('enforces all validation and state-transition errors on report actions', function (): void {
    $createPack = new CreateReportPack();
    $addContent = new AddReportContent();
    $reviewReport = new ReviewReport();
    $deliverReport = new DeliverReport();
    $archiveReport = new ArchiveReport();

    expect(fn () => $createPack->handle([
        'report_ref' => ' ',
        'name' => 'Missing Ref',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'USD',
    ]))->toThrow(InvalidReport::class, 'A report pack requires reference, name, period, and currency.');

    expect(fn () => $createPack->handle([
        'report_ref' => 'RPT-BAD',
        'name' => 'Bad Dates',
        'period_start' => '2026-03-01',
        'period_end' => '2026-01-01',
        'currency' => 'USD',
    ]))->toThrow(InvalidReport::class, 'Report period end must not precede its start.');

    $pack = $createPack->handle([
        'report_ref' => 'RPT-ERR',
        'name' => 'Error Pack',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'USD',
    ]);

    expect(fn () => $addContent->narrative($pack, [
        'section_ref' => '',
        'title' => 'T',
        'body' => 'B',
    ]))->toThrow(InvalidReport::class, 'Narratives require section, title, and body.');

    expect(fn () => $addContent->chart($pack, [
        'chart_ref' => 'c1',
        'title' => 'T',
        'chart_type' => 'bar',
        'series' => 'not-an-array',
    ]))->toThrow(InvalidReport::class, 'Charts require reference, title, type, and series.');

    expect(fn () => $reviewReport->handle($pack, 'actor', 'unknown'))
        ->toThrow(InvalidReport::class, 'Review decision is approved, rejected, or requested.');

    // Cannot deliver or archive a draft report
    expect(fn () => $deliverReport->handle($pack, 'pdf'))
        ->toThrow(InvalidReport::class, 'Only approved reports can be delivered.');

    expect(fn () => $archiveReport->handle($pack))
        ->toThrow(InvalidReport::class, 'Only approved or delivered reports can be archived.');

    $approved = $reviewReport->handle($pack, 'actor', 'approved');

    // Cannot review an approved report
    expect(fn () => $reviewReport->handle($approved, 'actor', 'approved'))
        ->toThrow(InvalidReport::class, 'Only draft or in-review reports can be reviewed.');

    // Unsupported delivery format
    expect(fn () => $deliverReport->handle($approved, 'csv'))
        ->toThrow(InvalidReport::class, 'Delivery format must be pdf or spreadsheet.');
});

it('queries report packs with filters, pagination clamping, and summary counts', function (): void {
    $query = app(ReportQuery::class);
    expect($query->packs()->total())->toBe(0);

    $createPack = new CreateReportPack();
    $addContent = new AddReportContent();
    $reviewReport = new ReviewReport();

    $p1 = $createPack->handle([
        'team_id' => 1,
        'report_ref' => 'R1',
        'name' => 'Pack 1',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'USD',
    ]);
    $addContent->narrative($p1, ['section_ref' => 's1', 'title' => 'S1', 'body' => 'Body']);
    $addContent->chart($p1, ['chart_ref' => 'c1', 'title' => 'C1', 'chart_type' => 'bar', 'series' => [1, 2]]);
    $reviewReport->handle($p1, 'rev', 'approved');

    $createPack->handle([
        'team_id' => 2,
        'report_ref' => 'R2',
        'name' => 'Pack 2',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'currency' => 'EUR',
    ]);

    expect($query->packs()->total())->toBe(2)
        ->and($query->packs(teamId: 1)->total())->toBe(1)
        ->and($query->packs(status: 'draft')->total())->toBe(1)
        ->and($query->packs(perPage: 0)->perPage())->toBe(1)
        ->and($query->packs(perPage: 250)->perPage())->toBe(100);

    expect($query->summary($p1->refresh()))->toBe([
        'narratives' => 1,
        'charts' => 1,
        'reviews' => 1,
        'deliveries' => 0,
        'status' => 'approved',
    ]);
});

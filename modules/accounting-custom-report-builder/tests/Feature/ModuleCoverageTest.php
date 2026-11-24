<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CustomReportBuilder\Actions\CreateCustomReport;
use Liberu\Accounting\CustomReportBuilder\Actions\RequestReportExport;
use Liberu\Accounting\CustomReportBuilder\Actions\SaveReportVariant;
use Liberu\Accounting\CustomReportBuilder\CustomReportBuilderServiceProvider;
use Liberu\Accounting\CustomReportBuilder\Enums\ReportExportStatus;
use Liberu\Accounting\CustomReportBuilder\Exceptions\InvalidCustomReport;
use Liberu\Accounting\CustomReportBuilder\Models\CustomReport;
use Liberu\Accounting\CustomReportBuilder\Models\CustomReportExport;
use Liberu\Accounting\CustomReportBuilder\Models\CustomReportVariant;
use Liberu\Accounting\CustomReportBuilder\Queries\CustomReportQuery;

it('registers and boots the custom report builder service provider and migrations', function (): void {
    $provider = app()->getProvider(CustomReportBuilderServiceProvider::class)
        ?? new CustomReportBuilderServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(CustomReportBuilderServiceProvider::class)
        ->and(Schema::hasTable('accounting_custom_reports'))->toBeTrue()
        ->and(Schema::hasTable('accounting_custom_report_variants'))->toBeTrue()
        ->and(Schema::hasTable('accounting_custom_report_exports'))->toBeTrue();
});

it('exposes report export status enum cases and exception class', function (): void {
    expect(array_map(static fn (ReportExportStatus $status): string => $status->value, ReportExportStatus::cases()))
        ->toBe(['requested', 'processing', 'completed', 'failed'])
        ->and(ReportExportStatus::from('completed'))->toBe(ReportExportStatus::Completed);

    $exception = new InvalidCustomReport('Bad report definition.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad report definition.');
});

it('creates custom reports with json casts and validates required attributes', function (): void {
    $action = app(CreateCustomReport::class);

    $report = $action->handle([
        'team_id' => 10,
        'report_ref' => 'RPT-PL-01',
        'name' => 'Departmental P&L',
        'measures' => ['revenue', 'cogs', 'gross_margin'],
        'dimensions' => ['department', 'month'],
        'filters' => ['currency' => 'GBP'],
        'grouping' => ['department'],
        'formulas' => ['gross_margin' => 'revenue - cogs'],
        'comparisons' => ['prior_year' => true],
        'layout' => ['orientation' => 'landscape'],
        'permissions' => ['roles' => ['finance']],
    ]);

    expect($report)->toBeInstanceOf(CustomReport::class)
        ->and($report->measures)->toBe(['revenue', 'cogs', 'gross_margin'])
        ->and($report->dimensions)->toBe(['department', 'month'])
        ->and($report->filters)->toBe(['currency' => 'GBP'])
        ->and($report->grouping)->toBe(['department'])
        ->and($report->formulas)->toBe(['gross_margin' => 'revenue - cogs'])
        ->and($report->comparisons)->toBe(['prior_year' => true])
        ->and($report->layout)->toBe(['orientation' => 'landscape'])
        ->and($report->permissions)->toBe(['roles' => ['finance']]);
});

it('rejects invalid custom report inputs', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'report_ref' => 'RPT-1',
        'name' => 'Cash Summary',
        'measures' => ['cash_in'],
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateCustomReport::class)->handle($payload))
        ->toThrow(InvalidCustomReport::class, $expectedMessage);
})->with([
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank report_ref' => [['report_ref' => ''], 'report_ref is required.'],
    'blank name' => [['name' => '  '], 'name is required.'],
    'empty measures' => [['measures' => []], 'measures is required.'],
    'non-array measures' => [['measures' => 'revenue'], 'At least one governed measure is required.'],
]);

it('saves and updates report variants and validates export requests', function (): void {
    $report = app(CreateCustomReport::class)->handle([
        'team_id' => 20,
        'report_ref' => 'RPT-20',
        'name' => 'Aged AR',
        'measures' => ['open_balance'],
    ]);

    $saveVariant = app(SaveReportVariant::class);

    $variant = $saveVariant->handle($report, 'exec-view', ['columns' => ['customer', 'open_balance']]);
    expect($variant)->toBeInstanceOf(CustomReportVariant::class)
        ->and($variant->team_id)->toBe(20)
        ->and($variant->configuration)->toBe(['columns' => ['customer', 'open_balance']])
        ->and($variant->report->is($report))->toBeTrue();

    // Updating the same variant_ref updates configuration in place.
    $updatedVariant = $saveVariant->handle($report, 'exec-view', ['columns' => ['customer', 'bucket_90']]);
    expect($updatedVariant->id)->toBe($variant->id)
        ->and($updatedVariant->configuration)->toBe(['columns' => ['customer', 'bucket_90']])
        ->and($report->variants()->count())->toBe(1);

    $exportAction = app(RequestReportExport::class);

    expect(fn () => $exportAction->handle($report, 'json'))
        ->toThrow(InvalidCustomReport::class, 'Export format is not supported.');

    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $export = $exportAction->handle($report, $format, ['period' => '2026-Q3']);
        expect($export)->toBeInstanceOf(CustomReportExport::class)
            ->and($export->format)->toBe($format)
            ->and($export->status)->toBe(ReportExportStatus::Requested)
            ->and($export->parameters)->toBe(['period' => '2026-Q3'])
            ->and($export->report->is($report))->toBeTrue();
    }

    $firstExport = $report->exports()->first();
    $firstExport->update(['status' => ReportExportStatus::Completed, 'completed_at' => now()]);
    expect($firstExport->fresh()->completed_at)->not->toBeNull()
        ->and($firstExport->fresh()->status)->toBe(ReportExportStatus::Completed);
});

it('queries custom reports by team with variants and exports eager-loaded', function (): void {
    $query = app(CustomReportQuery::class);

    expect($query->forTeam(777))->toBeEmpty();

    $create = app(CreateCustomReport::class);
    $r1 = $create->handle([
        'team_id' => 30,
        'report_ref' => 'RPT-30-A',
        'name' => 'Report A',
        'measures' => ['net_income'],
    ]);
    app(SaveReportVariant::class)->handle($r1, 'default', ['sort' => 'asc']);
    app(RequestReportExport::class)->handle($r1, 'pdf');

    $create->handle([
        'team_id' => 31,
        'report_ref' => 'RPT-31-A',
        'name' => 'Other Team Report',
        'measures' => ['net_income'],
    ]);

    $results = $query->forTeam(30);

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($r1->id)
        ->and($results->first()->relationLoaded('variants'))->toBeTrue()
        ->and($results->first()->relationLoaded('exports'))->toBeTrue()
        ->and($results->first()->variants)->toHaveCount(1)
        ->and($results->first()->exports)->toHaveCount(1);
});

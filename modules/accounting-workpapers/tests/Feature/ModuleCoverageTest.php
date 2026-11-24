<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Workpapers\Actions\AddWorkpaperAttachment;
use Liberu\Accounting\Workpapers\Actions\AddWorkpaperProcedure;
use Liberu\Accounting\Workpapers\Actions\AddWorkpaperReference;
use Liberu\Accounting\Workpapers\Actions\AssignWorkpaperReviewer;
use Liberu\Accounting\Workpapers\Actions\ConcludeWorkpaper;
use Liberu\Accounting\Workpapers\Actions\CreateWorkpaper;
use Liberu\Accounting\Workpapers\Actions\RequestWorkpaperExport;
use Liberu\Accounting\Workpapers\Actions\RolloverWorkpaper;
use Liberu\Accounting\Workpapers\Enums\ProcedureStatus;
use Liberu\Accounting\Workpapers\Enums\WorkpaperStatus;
use Liberu\Accounting\Workpapers\Exceptions\InvalidWorkpaper;
use Liberu\Accounting\Workpapers\Models\Workpaper;
use Liberu\Accounting\Workpapers\Models\WorkpaperAttachment;
use Liberu\Accounting\Workpapers\Models\WorkpaperExport;
use Liberu\Accounting\Workpapers\Models\WorkpaperProcedure;
use Liberu\Accounting\Workpapers\Models\WorkpaperReference;
use Liberu\Accounting\Workpapers\WorkpapersServiceProvider;

it('boots the workpapers service provider and loads migrations', function (): void {
    $provider = $this->app->register(WorkpapersServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(WorkpapersServiceProvider::class)
        ->and(Schema::hasTable('accounting_workpapers'))->toBeTrue()
        ->and(Schema::hasTable('accounting_workpaper_references'))->toBeTrue()
        ->and(Schema::hasTable('accounting_workpaper_procedures'))->toBeTrue()
        ->and(Schema::hasTable('accounting_workpaper_attachments'))->toBeTrue()
        ->and(Schema::hasTable('accounting_workpaper_rollovers'))->toBeTrue()
        ->and(Schema::hasTable('accounting_workpaper_exports'))->toBeTrue();
});

it('exposes workpaper and procedure status enums and exception', function (): void {
    expect(WorkpaperStatus::cases())->toHaveCount(4)
        ->and(WorkpaperStatus::Draft->value)->toBe('draft')
        ->and(WorkpaperStatus::InProgress->value)->toBe('in_progress')
        ->and(WorkpaperStatus::Review->value)->toBe('review')
        ->and(WorkpaperStatus::Complete->value)->toBe('complete')
        ->and(ProcedureStatus::cases())->toHaveCount(3)
        ->and(ProcedureStatus::Pending->value)->toBe('pending')
        ->and(ProcedureStatus::Passed->value)->toBe('passed')
        ->and(ProcedureStatus::Failed->value)->toBe('failed');

    $exception = new InvalidWorkpaper('Invalid workpaper state');
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toBe('Invalid workpaper state');
});

it('creates workpapers and validates required fields and period chronology', function (): void {
    $action = new CreateWorkpaper();

    $workpaper = $action->handle([
        'team_id' => 10,
        'title' => 'Cash Lead Schedule',
        'reference' => 'WP-100',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'preparer_id' => 5,
        'metadata' => ['section' => 'assets'],
    ]);

    expect($workpaper)->toBeInstanceOf(Workpaper::class)
        ->and($workpaper->status)->toBe(WorkpaperStatus::Draft)
        ->and($workpaper->period_start)->toBeInstanceOf(Carbon::class)
        ->and($workpaper->period_end)->toBeInstanceOf(Carbon::class)
        ->and($workpaper->metadata)->toBe(['section' => 'assets']);

    $inProgress = $action->handle([
        'team_id' => 10,
        'title' => 'AP Lead Schedule',
        'status' => WorkpaperStatus::InProgress,
    ]);
    expect($inProgress->status)->toBe(WorkpaperStatus::InProgress);

    expect(fn () => $action->handle(['team_id' => 10, 'title' => '']))
        ->toThrow(InvalidWorkpaper::class, 'A team and workpaper title are required.');

    expect(fn () => $action->handle(['title' => 'No Team']))
        ->toThrow(InvalidWorkpaper::class, 'A team and workpaper title are required.');

    expect(fn () => $action->handle([
        'team_id' => 10,
        'title' => 'Bad Dates',
        'period_start' => '2026-12-31',
        'period_end' => '2026-01-01',
    ]))->toThrow(InvalidWorkpaper::class, 'The workpaper period must be chronological.');
});

it('manages workpaper references, procedures, attachments, reviewer, conclusion, exports, and rollover', function (): void {
    $workpaper = (new CreateWorkpaper())->handle([
        'team_id' => 12,
        'title' => 'Revenue Cut-Off',
        'reference' => 'WP-400',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'preparer_id' => 7,
        'metadata' => ['materiality' => 5000],
    ]);

    $refAction = new AddWorkpaperReference();
    $reference = $refAction->handle($workpaper, [
        'label' => 'Trial Balance',
        'target_type' => 'report',
        'target_id' => 'tb-2026',
        'notes' => 'Agreed to GL',
    ]);
    expect($reference)->toBeInstanceOf(WorkpaperReference::class)
        ->and($reference->workpaper->is($workpaper))->toBeTrue();
    expect(fn () => $refAction->handle($workpaper, ['label' => '']))
        ->toThrow(InvalidWorkpaper::class, 'A reference label is required.');

    $procAction = new AddWorkpaperProcedure();
    $procedure = $procAction->handle($workpaper, [
        'description' => 'Sample 25 year-end invoices',
        'performed_by' => 7,
        'performed_at' => '2026-12-20 10:00:00',
        'evidence' => 'All matched to dispatch notes',
    ]);
    $passedProc = $procAction->handle($workpaper, [
        'description' => 'Recalculate deferred revenue',
        'status' => ProcedureStatus::Passed,
    ]);
    expect($procedure)->toBeInstanceOf(WorkpaperProcedure::class)
        ->and($procedure->status)->toBe(ProcedureStatus::Pending)
        ->and($procedure->performed_at)->toBeInstanceOf(Carbon::class)
        ->and($procedure->workpaper->is($workpaper))->toBeTrue()
        ->and($passedProc->status)->toBe(ProcedureStatus::Passed);
    expect(fn () => $procAction->handle($workpaper, ['description' => '']))
        ->toThrow(InvalidWorkpaper::class, 'A procedure description is required.');

    $attAction = new AddWorkpaperAttachment();
    $attachment = $attAction->handle($workpaper, [
        'name' => 'bank-statement.pdf',
        'path' => 'workpapers/12/bank-statement.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);
    $customDiskAttachment = $attAction->handle($workpaper, [
        'name' => 'schedule.csv',
        'disk' => 's3',
        'path' => 'workpapers/12/schedule.csv',
    ]);
    expect($attachment)->toBeInstanceOf(WorkpaperAttachment::class)
        ->and($attachment->disk)->toBe('private')
        ->and($attachment->workpaper->is($workpaper))->toBeTrue()
        ->and($customDiskAttachment->disk)->toBe('s3');
    expect(fn () => $attAction->handle($workpaper, ['name' => '', 'path' => 'a/b']))
        ->toThrow(InvalidWorkpaper::class, 'An attachment name and path are required.');
    expect(fn () => $attAction->handle($workpaper, ['name' => 'file.pdf', 'path' => '']))
        ->toThrow(InvalidWorkpaper::class, 'An attachment name and path are required.');

    $reviewed = (new AssignWorkpaperReviewer())->handle($workpaper, 99);
    expect($reviewed->reviewer_id)->toBe(99);

    $concludeAction = new ConcludeWorkpaper();
    expect(fn () => $concludeAction->handle($workpaper, ''))
        ->toThrow(InvalidWorkpaper::class, 'A conclusion is required.');
    $concluded = $concludeAction->handle($workpaper, 'Balances are fairly stated.');
    expect($concluded->status)->toBe(WorkpaperStatus::Complete)
        ->and($concluded->conclusion)->toBe('Balances are fairly stated.');

    $exportAction = new RequestWorkpaperExport();
    foreach (['csv', 'json', 'pdf'] as $format) {
        $export = $exportAction->handle($workpaper, $format);
        expect($export)->toBeInstanceOf(WorkpaperExport::class)
            ->and($export->format)->toBe($format)
            ->and($export->status)->toBe('pending')
            ->and($export->workpaper->is($workpaper))->toBeTrue();
    }
    expect(fn () => $exportAction->handle($workpaper, 'xlsx'))
        ->toThrow(InvalidWorkpaper::class, 'The export format must be csv, json, or pdf.');

    $workpaper->load(['references', 'procedures', 'attachments', 'exports']);
    expect($workpaper->references)->toHaveCount(1)
        ->and($workpaper->procedures)->toHaveCount(2)
        ->and($workpaper->attachments)->toHaveCount(2)
        ->and($workpaper->exports)->toHaveCount(3);

    $rolledOver = (new RolloverWorkpaper())->handle($workpaper, [
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
    ]);
    expect($rolledOver->id)->not->toBe($workpaper->id)
        ->and($rolledOver->status)->toBe(WorkpaperStatus::Draft)
        ->and($rolledOver->title)->toBe('Revenue Cut-Off')
        ->and($rolledOver->references()->count())->toBe(1)
        ->and(DB::table('accounting_workpaper_rollovers')
            ->where('source_workpaper_id', $workpaper->id)
            ->where('target_workpaper_id', $rolledOver->id)
            ->value('status'))->toBe('completed');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\RevenueRecognition\Actions\CreateRevenueSchedule;
use Liberu\Accounting\RevenueRecognition\Actions\ModifyRevenueSchedule;
use Liberu\Accounting\RevenueRecognition\Actions\RecognizeDueRevenue;
use Liberu\Accounting\RevenueRecognition\Actions\ReconcileRevenueRun;
use Liberu\Accounting\RevenueRecognition\Enums\RecognitionRunStatus;
use Liberu\Accounting\RevenueRecognition\Enums\RecognitionStatus;
use Liberu\Accounting\RevenueRecognition\Exceptions\InvalidRecognition;
use Liberu\Accounting\RevenueRecognition\Models\RevenueAllocationReference;
use Liberu\Accounting\RevenueRecognition\Models\RevenueModification;
use Liberu\Accounting\RevenueRecognition\Models\RevenueObligation;
use Liberu\Accounting\RevenueRecognition\Models\RevenueRecognitionRun;
use Liberu\Accounting\RevenueRecognition\Models\RevenueReconciliation;
use Liberu\Accounting\RevenueRecognition\Models\RevenueSchedule;
use Liberu\Accounting\RevenueRecognition\Models\RevenueScheduleEntry;
use Liberu\Accounting\RevenueRecognition\RevenueRecognitionServiceProvider;

it('registers the revenue recognition service provider and runs migrations', function (): void {
    expect(app()->getProvider(RevenueRecognitionServiceProvider::class))
        ->toBeInstanceOf(RevenueRecognitionServiceProvider::class);

    foreach ([
        'accounting_revenue_obligations',
        'accounting_revenue_allocation_references',
        'accounting_revenue_schedules',
        'accounting_revenue_schedule_entries',
        'accounting_revenue_modifications',
        'accounting_revenue_recognition_runs',
        'accounting_revenue_reconciliations',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('exposes all revenue recognition enum cases', function (): void {
    expect(array_column(RecognitionStatus::cases(), 'value'))
        ->toBe(['draft', 'active', 'completed', 'cancelled', 'failed']);

    expect(array_column(RecognitionRunStatus::cases(), 'value'))
        ->toBe(['pending', 'running', 'completed', 'failed', 'reconciled']);
});

it('creates revenue schedules with obligation and entries and validates inputs', function (): void {
    $create = new CreateRevenueSchedule();

    $allocationRef = RevenueAllocationReference::create([
        'team_id' => 1,
        'reference_type' => 'contract',
        'reference_id' => 'CON-100',
        'allocation' => ['standalone_selling_price' => 100.0],
        'status' => 'active',
        'metadata' => ['method' => 'ssp'],
    ]);
    expect($allocationRef->allocation)->toBe(['standalone_selling_price' => 100.0]);

    $schedule = $create->handle([
        'team_id' => 1,
        'source_type' => 'invoice',
        'source_id' => 'INV-900',
        'description' => 'Annual SaaS Subscription',
        'currency' => 'USD',
        'total_amount' => 100.00,
        'start_date' => '2026-01-01',
        'periods' => 3,
        'allocation_reference_id' => $allocationRef->id,
        'deferred_account_ref' => '2100-DEFERRED',
        'revenue_account_ref' => '4000-REVENUE',
        'funded' => true,
        'metadata' => ['plan' => 'pro'],
    ]);

    expect($schedule)->toBeInstanceOf(RevenueSchedule::class)
        ->and($schedule->status)->toBe(RecognitionStatus::Active)
        ->and($schedule->funded)->toBeTrue()
        ->and($schedule->obligation)->toBeInstanceOf(RevenueObligation::class)
        ->and($schedule->obligation->schedules()->count())->toBe(1)
        ->and($schedule->entries)->toHaveCount(3);

    $amounts = $schedule->entries->sortBy('period_number')->pluck('amount')->all();
    expect($amounts)->toBe(['33.33', '33.33', '33.34']);

    $dates = $schedule->entries->sortBy('period_number')->map(fn (RevenueScheduleEntry $e) => $e->recognition_date->toDateString())->values()->all();
    expect($dates)->toBe(['2026-01-01', '2026-02-01', '2026-03-01']);

    // Zero total_amount is allowed
    $zeroSchedule = $create->handle([
        'total_amount' => 0,
        'start_date' => '2026-01-01',
        'periods' => 1,
        'deferred_account_ref' => '2100',
        'revenue_account_ref' => '4000',
    ]);
    expect($zeroSchedule->entries->first()->amount)->toBe('0.00');

    // Validation failures
    expect(fn () => $create->handle([
        'periods' => 0, 'total_amount' => 100, 'start_date' => '2026-01-01',
        'deferred_account_ref' => '2100', 'revenue_account_ref' => '4000',
    ]))->toThrow(InvalidRecognition::class, 'periods must be at least 1.');

    expect(fn () => $create->handle([
        'periods' => 1, 'total_amount' => -1, 'start_date' => '2026-01-01',
        'deferred_account_ref' => '2100', 'revenue_account_ref' => '4000',
    ]))->toThrow(InvalidRecognition::class, 'total_amount cannot be negative.');

    expect(fn () => $create->handle([
        'periods' => 1, 'total_amount' => 10, 'start_date' => '2026-01-01',
        'deferred_account_ref' => '4000', 'revenue_account_ref' => '4000',
    ]))->toThrow(InvalidRecognition::class, 'Deferred and revenue account references must be different.');

    expect(fn () => $create->handle([
        'periods' => 1, 'total_amount' => 10, 'start_date' => '',
        'deferred_account_ref' => '2100', 'revenue_account_ref' => '4000',
    ]))->toThrow(InvalidRecognition::class, 'start_date is required.');
});

it('modifies active schedules and rejects completed, cancelled, or reasonless modifications', function (): void {
    $create = new CreateRevenueSchedule();
    $modify = new ModifyRevenueSchedule();

    $schedule = $create->handle([
        'total_amount' => 200,
        'start_date' => '2026-01-01',
        'periods' => 2,
        'deferred_account_ref' => '2100',
        'revenue_account_ref' => '4000',
    ]);

    $modification = $modify->handle($schedule, [
        'effective_date' => '2026-02-01',
        'amount_delta' => 50.00,
        'reason' => 'Seat upgrade mid-term',
        'metadata' => ['seats' => 5],
    ]);

    expect($modification)->toBeInstanceOf(RevenueModification::class)
        ->and($modification->amount_delta)->toBe('50.00')
        ->and($modification->status)->toBe('approved')
        ->and($schedule->modifications()->count())->toBe(1);

    expect(fn () => $modify->handle($schedule, ['reason' => '']))
        ->toThrow(InvalidRecognition::class, 'A modification reason is required.');

    $schedule->update(['status' => RecognitionStatus::Completed]);
    expect(fn () => $modify->handle($schedule->fresh(), ['reason' => 'Too late']))
        ->toThrow(InvalidRecognition::class, 'A completed or cancelled schedule cannot be modified.');

    $schedule->update(['status' => RecognitionStatus::Cancelled]);
    expect(fn () => $modify->handle($schedule->fresh(), ['reason' => 'Cancelled']))
        ->toThrow(InvalidRecognition::class, 'A completed or cancelled schedule cannot be modified.');
});

it('recognizes due revenue for funded schedules, skips unfunded ones, handles failures, and reconciles runs', function (): void {
    $create = new CreateRevenueSchedule();
    $recognize = new RecognizeDueRevenue();
    $reconcile = new ReconcileRevenueRun();

    // Unfunded schedule is skipped
    $unfunded = $create->handle([
        'total_amount' => 120,
        'start_date' => '2026-01-01',
        'periods' => 2,
        'deferred_account_ref' => '2100',
        'revenue_account_ref' => '4000',
        'funded' => false,
    ]);
    $skippedRun = $recognize->handle($unfunded, '2026-03-01', 1);
    expect($skippedRun->status)->toBe(RecognitionRunStatus::Completed)
        ->and($skippedRun->metadata)->toBe(['skipped' => 'schedule is not funded or active'])
        ->and($skippedRun->processed_entries)->toBe(0);

    // Funded schedule recognizes due entries incrementally then completes
    $funded = $create->handle([
        'team_id' => 2,
        'total_amount' => 120,
        'start_date' => '2026-01-01',
        'periods' => 2,
        'deferred_account_ref' => '2100',
        'revenue_account_ref' => '4000',
        'funded' => true,
    ]);

    $firstRun = $recognize->handle($funded, '2026-01-31', 2);
    expect($firstRun->status)->toBe(RecognitionRunStatus::Completed)
        ->and($firstRun->processed_entries)->toBe(1)
        ->and($funded->fresh()->status)->toBe(RecognitionStatus::Active);

    $recognizedEntry = $funded->entries()->where('period_number', 1)->first();
    expect($recognizedEntry->status)->toBe(RecognitionStatus::Completed)
        ->and($recognizedEntry->ledger_reference)->toBe('revenue-recognition:'.$recognizedEntry->id)
        ->and($recognizedEntry->recognized_at)->not->toBeNull();

    $secondRun = $recognize->handle($funded->fresh(), '2026-02-28', 2);
    expect($secondRun->processed_entries)->toBe(1)
        ->and($funded->fresh()->status)->toBe(RecognitionStatus::Completed);

    // Reconcile matching run
    $reconciled = $reconcile->handle($secondRun, [
        ['reference_id' => $funded->id, 'expected_amount' => 120.00, 'recognized_amount' => 120.00],
    ]);
    expect($reconciled->status)->toBe(RecognitionRunStatus::Reconciled)
        ->and($reconciled->metadata)->toHaveKey('reconciled_at');

    $recRow = RevenueReconciliation::where('run_id', $reconciled->id)->first();
    expect($recRow->status)->toBe('matched')
        ->and($recRow->variance)->toBe('0.00');

    // Reconcile run with variance > 0.01 fails
    $varianceRun = $reconcile->handle($firstRun, [
        ['reference_type' => 'schedule', 'reference_id' => 'SCH-ERR', 'expected_amount' => 120.00, 'recognized_amount' => 60.00, 'notes' => 'Pending period 2'],
    ]);
    expect($varianceRun->status)->toBe(RecognitionRunStatus::Failed);
    expect(RevenueReconciliation::where('run_id', $varianceRun->id)->first()?->status)->toBe('exception');

    // Error handling branch inside RecognizeDueRevenue
    $failingSchedule = $create->handle([
        'total_amount' => 50,
        'start_date' => '2026-05-01',
        'periods' => 1,
        'deferred_account_ref' => '2100',
        'revenue_account_ref' => '4000',
        'funded' => true,
    ]);

    $shouldFail = true;
    RevenueScheduleEntry::updating(function (RevenueScheduleEntry $entry) use ($failingSchedule, &$shouldFail): void {
        if ($shouldFail && $entry->schedule_id === $failingSchedule->id) {
            $shouldFail = false;
            throw new \RuntimeException('Ledger posting failed.');
        }
    });

    expect(fn () => $recognize->handle($failingSchedule, '2026-05-31', 9))
        ->toThrow(\RuntimeException::class, 'Ledger posting failed.');

    $failedRun = RevenueRecognitionRun::where('team_id', 9)->latest('id')->first();
    expect($failedRun->status)->toBe(RecognitionRunStatus::Failed)
        ->and($failedRun->failed_entries)->toBe(1)
        ->and($failedRun->errors)->toBe(['message' => 'Ledger posting failed.']);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ProjectBilling\Actions\HandoffProjectBilling;
use Liberu\Accounting\ProjectBilling\Actions\RecordProjectBilling;
use Liberu\Accounting\ProjectBilling\Enums\BillingMethod;
use Liberu\Accounting\ProjectBilling\Enums\BillingStatus;
use Liberu\Accounting\ProjectBilling\Exceptions\InvalidBilling;
use Liberu\Accounting\ProjectBilling\Models\ProjectBilling;
use Liberu\Accounting\ProjectBilling\ProjectBillingServiceProvider;
use Liberu\Accounting\ProjectBilling\Queries\ProjectBillingSummary;
use Liberu\Accounting\ProjectsAndJobs\Actions\CreateProjectJob;

it('boots the service provider and loads the project billings table', function (): void {
    expect(app()->getProvider(ProjectBillingServiceProvider::class))
        ->toBeInstanceOf(ProjectBillingServiceProvider::class);

    expect(Schema::hasTable('accounting_project_billings'))->toBeTrue();
});

it('exposes billing method and billing status enums', function (): void {
    expect(array_map(fn (BillingMethod $c): string => $c->value, BillingMethod::cases()))
        ->toBe(['fixed_fee', 'time_material', 'milestone', 'progress', 'retainer']);

    expect(array_map(fn (BillingStatus $c): string => $c->value, BillingStatus::cases()))
        ->toBe(['draft', 'ready', 'handed_off', 'cancelled']);
});

it('records, upserts, and calculates billable totals for project billings', function (): void {
    $project = app(CreateProjectJob::class)->handle(['name' => 'Website Redesign']);
    $recordAction = app(RecordProjectBilling::class);

    $billing = $recordAction->handle([
        'team_id' => 3,
        'project_job_id' => $project->id,
        'method' => 'time_material',
        'status' => BillingStatus::Ready,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'currency' => 'GBP',
        'quantity' => 10,
        'rate' => 120,
        'amount' => 1200,
        'progress_percent' => 50,
        'billable_time_amount' => 800,
        'billable_expense_amount' => 150,
        'retainer_amount' => 200,
        'write_up_down_amount' => -50,
        'source_ref' => 'RUN-01',
        'metadata' => ['cycle' => 'march'],
    ]);

    expect($billing)->toBeInstanceOf(ProjectBilling::class)
        ->and($billing->method)->toBe(BillingMethod::TimeMaterial)
        ->and($billing->status)->toBe(BillingStatus::Ready)
        ->and($billing->projectJob->is($project))->toBeTrue()
        ->and($billing->billableTotal())->toBe(900.0)
        ->and($billing->metadata)->toBe(['cycle' => 'march']);

    // Upserting same project/method/source_ref/period/team updates existing record
    $updated = $recordAction->handle([
        'team_id' => 3,
        'project_job_id' => $project->id,
        'method' => 'time_material',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'source_ref' => 'RUN-01',
        'amount' => 1350,
        'billable_time_amount' => 950,
    ]);

    expect($updated->id)->toBe($billing->id)
        ->and((float) $updated->amount)->toBe(1350.0)
        ->and($updated->status)->toBe(BillingStatus::Draft);

    // Defaults period_start / period_end when omitted
    $defaultDates = $recordAction->handle([
        'project_job_id' => $project->id,
        'method' => 'fixed_fee',
        'amount' => 500,
    ]);
    expect($defaultDates->method)->toBe(BillingMethod::FixedFee);
});

it('validates billing periods, progress percent, and non-negative numeric fields', function (): void {
    $action = app(RecordProjectBilling::class);

    expect(fn () => $action->handle([
        'project_job_id' => 1,
        'method' => 'milestone',
        'period_start' => '2026-04-10',
        'period_end' => '2026-04-01',
    ]))->toThrow(InvalidBilling::class, 'The billing period must be ordered.');

    expect(fn () => $action->handle([
        'project_job_id' => 1,
        'method' => 'progress',
        'progress_percent' => -1,
    ]))->toThrow(InvalidBilling::class, 'Progress must be between 0 and 100 percent.');

    expect(fn () => $action->handle([
        'project_job_id' => 1,
        'method' => 'progress',
        'progress_percent' => 100.01,
    ]))->toThrow(InvalidBilling::class, 'Progress must be between 0 and 100 percent.');

    foreach (['quantity', 'rate', 'amount', 'billable_time_amount', 'billable_expense_amount', 'retainer_amount'] as $field) {
        expect(fn () => $action->handle([
            'project_job_id' => 1,
            'method' => 'retainer',
            $field => -10,
        ]))->toThrow(InvalidBilling::class, $field.' cannot be negative.');
    }
});

it('hands off active billings and rejects cancelled billings', function (): void {
    $handoff = app(HandoffProjectBilling::class);
    $record = app(RecordProjectBilling::class);

    $billing = $record->handle([
        'project_job_id' => 42,
        'method' => 'milestone',
        'status' => BillingStatus::Ready,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'amount' => 1000,
        'invoice_ref' => 'INV-PRE',
    ]);

    $handedOff = $handoff->handle($billing);
    expect($handedOff->status)->toBe(BillingStatus::HandedOff)
        ->and($handedOff->invoice_ref)->toBe('INV-PRE');

    $handedOffWithRef = $handoff->handle($handedOff, 'INV-FINAL');
    expect($handedOffWithRef->invoice_ref)->toBe('INV-FINAL');

    $cancelled = $record->handle([
        'project_job_id' => 42,
        'method' => 'retainer',
        'status' => BillingStatus::Cancelled,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'amount' => 500,
    ]);

    expect(fn () => $handoff->handle($cancelled, 'INV-NO'))
        ->toThrow(InvalidBilling::class, 'Cancelled billing cannot be handed off.');
});

it('summarizes project billing totals for empty and populated projects', function (): void {
    $summary = app(ProjectBillingSummary::class);
    $record = app(RecordProjectBilling::class);
    $handoff = app(HandoffProjectBilling::class);

    expect($summary->forProject(999))->toBe([
        'amount' => 0.0,
        'billable_time_expense' => 0.0,
        'retainer' => 0.0,
        'write_up_down' => 0.0,
        'handed_off' => 0,
        'entries' => 0,
    ]);

    $first = $record->handle([
        'team_id' => 1,
        'project_job_id' => 50,
        'method' => 'time_material',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'amount' => 1000,
        'billable_time_amount' => 700,
        'billable_expense_amount' => 200,
        'retainer_amount' => 100,
        'write_up_down_amount' => 50,
    ]);
    $handoff->handle($first, 'INV-50');

    $record->handle([
        'team_id' => 1,
        'project_job_id' => 50,
        'method' => 'retainer',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'amount' => 400,
        'billable_time_amount' => 0,
        'billable_expense_amount' => 0,
        'retainer_amount' => 400,
        'write_up_down_amount' => -25,
    ]);

    $record->handle([
        'team_id' => 2,
        'project_job_id' => 50,
        'method' => 'milestone',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'amount' => 999,
    ]);

    expect($summary->forProject(50, 1))->toBe([
        'amount' => 1400.0,
        'billable_time_expense' => 925.0,
        'retainer' => 500.0,
        'write_up_down' => 25.0,
        'handed_off' => 1,
        'entries' => 2,
    ]);

    expect($summary->forProject(50)['entries'])->toBe(3);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\TimeTracking\Actions\ApproveTimeEntry;
use Liberu\Accounting\TimeTracking\Actions\CreateTimeEntry;
use Liberu\Accounting\TimeTracking\Actions\StartTimer;
use Liberu\Accounting\TimeTracking\Actions\StopTimer;
use Liberu\Accounting\TimeTracking\Actions\SubmitTimeEntry;
use Liberu\Accounting\TimeTracking\Enums\TimeEntryStatus;
use Liberu\Accounting\TimeTracking\Enums\TimerStatus;
use Liberu\Accounting\TimeTracking\Exceptions\InvalidTimeEntry;
use Liberu\Accounting\TimeTracking\Models\TimeEntry;
use Liberu\Accounting\TimeTracking\Models\TimeExport;
use Liberu\Accounting\TimeTracking\Models\TimeTimer;
use Liberu\Accounting\TimeTracking\TimeTrackingServiceProvider;

it('registers time tracking service provider and creates tables', function (): void {
    expect(app()->getProvider(TimeTrackingServiceProvider::class))
        ->toBeInstanceOf(TimeTrackingServiceProvider::class);
    expect(Schema::hasTable('accounting_time_entries'))->toBeTrue();
    expect(Schema::hasTable('accounting_time_timers'))->toBeTrue();
    expect(Schema::hasTable('accounting_time_exports'))->toBeTrue();
});

it('exposes time entry and timer status enums', function (): void {
    expect(array_map(static fn (TimeEntryStatus $s): string => $s->value, TimeEntryStatus::cases()))
        ->toBe(['draft', 'submitted', 'approved', 'rejected', 'corrected', 'exported']);
    expect(array_map(static fn (TimerStatus $s): string => $s->value, TimerStatus::cases()))
        ->toBe(['running', 'stopped']);
});

it('creates time entries, validates bounds, and supports model casts', function (): void {
    $create = app(CreateTimeEntry::class);

    $entry = $create->handle([
        'team_id' => 1,
        'worker_ref' => 'emp-1',
        'customer_ref' => 'cust-1',
        'project_ref' => 'proj-1',
        'task_ref' => 'task-1',
        'worked_on' => '2026-05-10',
        'hours' => '7.5',
        'billable_rate' => '150.00',
        'cost_rate' => '75.00',
        'currency' => 'USD',
        'billable' => true,
        'description' => 'Audit prep',
        'metadata' => ['tag' => 'audit'],
    ]);

    expect($entry)->toBeInstanceOf(TimeEntry::class)
        ->and($entry->status)->toBe(TimeEntryStatus::Draft)
        ->and($entry->worked_on->toDateString())->toBe('2026-05-10')
        ->and($entry->hours)->toBe('7.5000')
        ->and($entry->billable_rate)->toBe('150.000000')
        ->and($entry->cost_rate)->toBe('75.000000')
        ->and($entry->billable)->toBeTrue()
        ->and($entry->metadata)->toBe(['tag' => 'audit']);

    $maxEntry = $create->handle([
        'team_id' => 1,
        'worker_ref' => 'emp-1',
        'worked_on' => '2026-05-11',
        'hours' => 24,
        'status' => TimeEntryStatus::Corrected,
    ]);
    expect($maxEntry->status)->toBe(TimeEntryStatus::Corrected);

    $invalidCases = [
        ['team_id' => 1, 'worked_on' => '2026-05-10', 'hours' => 4],
        ['team_id' => 1, 'worker_ref' => 'emp-1', 'hours' => 4],
        ['team_id' => 1, 'worker_ref' => 'emp-1', 'worked_on' => '2026-05-10', 'hours' => 0],
        ['team_id' => 1, 'worker_ref' => 'emp-1', 'worked_on' => '2026-05-10', 'hours' => -2],
        ['team_id' => 1, 'worker_ref' => 'emp-1', 'worked_on' => '2026-05-10', 'hours' => 24.5],
    ];

    foreach ($invalidCases as $payload) {
        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidTimeEntry::class, 'A worker, work date, and hours between 0 and 24 are required.');
    }
});

it('submits and approves time entries with status transition checks', function (): void {
    $create = app(CreateTimeEntry::class);
    $submit = app(SubmitTimeEntry::class);
    $approve = app(ApproveTimeEntry::class);

    $draft = $create->handle([
        'team_id' => 1,
        'worker_ref' => 'emp-2',
        'worked_on' => '2026-05-12',
        'hours' => 3.5,
    ]);

    expect(fn () => $approve->handle($draft))
        ->toThrow(InvalidTimeEntry::class, 'Only submitted entries can be approved.');

    $submitted = $submit->handle($draft);
    expect($submitted->status)->toBe(TimeEntryStatus::Submitted);

    expect(fn () => $submit->handle($submitted))
        ->toThrow(InvalidTimeEntry::class, 'Only draft or corrected entries can be submitted.');

    $approved = $approve->handle($submitted);
    expect($approved->status)->toBe(TimeEntryStatus::Approved);

    expect(fn () => $approve->handle($approved))
        ->toThrow(InvalidTimeEntry::class, 'Only submitted entries can be approved.');

    $corrected = $create->handle([
        'team_id' => 1,
        'worker_ref' => 'emp-2',
        'worked_on' => '2026-05-13',
        'hours' => 2,
        'status' => TimeEntryStatus::Corrected,
    ]);
    expect($submit->handle($corrected)->status)->toBe(TimeEntryStatus::Submitted);
});

it('starts and stops timers while stopping prior running timers for the same worker', function (): void {
    $start = app(StartTimer::class);
    $stop = app(StopTimer::class);

    $first = $start->handle([
        'team_id' => 5,
        'worker_ref' => 'emp-timer',
        'project_ref' => 'proj-a',
    ]);

    $otherWorkerTimer = $start->handle([
        'team_id' => 5,
        'worker_ref' => 'emp-other',
        'project_ref' => 'proj-b',
        'started_at' => now()->subHour(),
    ]);

    expect($first)->toBeInstanceOf(TimeTimer::class)
        ->and($first->status)->toBe(TimerStatus::Running)
        ->and($first->started_at)->not->toBeNull();

    $second = $start->handle([
        'team_id' => 5,
        'worker_ref' => 'emp-timer',
        'project_ref' => 'proj-c',
    ]);

    expect($first->refresh()->status)->toBe(TimerStatus::Stopped)
        ->and($first->stopped_at)->not->toBeNull()
        ->and($otherWorkerTimer->refresh()->status)->toBe(TimerStatus::Running)
        ->and($second->status)->toBe(TimerStatus::Running);

    $stopped = $stop->handle($second);
    expect($stopped->status)->toBe(TimerStatus::Stopped)
        ->and($stopped->stopped_at)->not->toBeNull();

    expect(fn () => $stop->handle($stopped))
        ->toThrow(InvalidTimeEntry::class, 'Only running timers can be stopped.');
});

it('persists and casts time export records', function (): void {
    $export = TimeExport::create([
        'team_id' => 7,
        'destination' => 'payroll',
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-15',
        'entry_count' => 14,
        'status' => 'completed',
        'exported_at' => '2026-05-16 09:00:00',
        'metadata' => ['batch' => 'B-1'],
    ]);

    expect($export)->toBeInstanceOf(TimeExport::class)
        ->and($export->period_start->toDateString())->toBe('2026-05-01')
        ->and($export->period_end->toDateString())->toBe('2026-05-15')
        ->and($export->exported_at->toDateTimeString())->toBe('2026-05-16 09:00:00')
        ->and($export->metadata)->toBe(['batch' => 'B-1']);
});

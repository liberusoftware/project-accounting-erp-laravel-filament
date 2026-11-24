<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\RecurringTransactions\Actions\ApproveRecurringTemplate;
use Liberu\Accounting\RecurringTransactions\Actions\CreateRecurringTemplate;
use Liberu\Accounting\RecurringTransactions\Actions\GenerateRecurringOccurrences;
use Liberu\Accounting\RecurringTransactions\Enums\OccurrenceStatus;
use Liberu\Accounting\RecurringTransactions\Enums\RecurringStatus;
use Liberu\Accounting\RecurringTransactions\Exceptions\InvalidRecurringTransaction;
use Liberu\Accounting\RecurringTransactions\Models\RecurringException;
use Liberu\Accounting\RecurringTransactions\Models\RecurringOccurrence;
use Liberu\Accounting\RecurringTransactions\Models\RecurringTemplate;
use Liberu\Accounting\RecurringTransactions\RecurringTransactionsServiceProvider;

it('registers the recurring transactions service provider and runs migrations', function (): void {
    expect(app()->getProvider(RecurringTransactionsServiceProvider::class))
        ->toBeInstanceOf(RecurringTransactionsServiceProvider::class);

    foreach ([
        'accounting_recurring_transaction_templates',
        'accounting_recurring_transaction_occurrences',
        'accounting_recurring_transaction_exceptions',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('accounting_recurring_transaction_templates', [
        'id', 'team_id', 'name', 'transaction_type', 'frequency', 'starts_on', 'next_run_on', 'ends_on', 'status', 'automatic', 'date_rules', 'amount_rules', 'payload', 'approved_by', 'approved_at', 'metadata',
    ]))->toBeTrue();
});

it('exposes all recurring status and occurrence status enum cases', function (): void {
    expect(array_column(RecurringStatus::cases(), 'value'))
        ->toBe(['draft', 'pending_approval', 'approved', 'active', 'expired', 'suspended', 'failed']);

    expect(array_column(OccurrenceStatus::cases(), 'value'))
        ->toBe(['draft', 'generated', 'failed', 'skipped']);
});

it('creates recurring templates and validates required fields and frequencies', function (): void {
    $create = new CreateRecurringTemplate();

    $template = $create->handle([
        'team_id' => 3,
        'name' => 'Monthly Office Rent',
        'transaction_type' => 'bill',
        'frequency' => 'monthly',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'automatic' => true,
        'date_rules' => ['day_of_month' => 1],
        'amount_rules' => ['fixed' => 2500],
        'payload' => ['amount' => 2500, 'currency' => 'USD'],
        'metadata' => ['department' => 'ops'],
    ]);

    expect($template)->toBeInstanceOf(RecurringTemplate::class)
        ->and($template->status)->toBe(RecurringStatus::Draft)
        ->and($template->automatic)->toBeTrue()
        ->and($template->next_run_on->toDateString())->toBe('2026-01-01')
        ->and($template->date_rules)->toBe(['day_of_month' => 1])
        ->and($template->amount_rules)->toBe(['fixed' => 2500])
        ->and($template->payload)->toBe(['amount' => 2500, 'currency' => 'USD']);

    foreach ([
        ['name' => '', 'transaction_type' => 'bill', 'frequency' => 'monthly', 'starts_on' => '2026-01-01'],
        ['name' => 'Rent', 'transaction_type' => '', 'frequency' => 'monthly', 'starts_on' => '2026-01-01'],
        ['name' => 'Rent', 'transaction_type' => 'bill', 'frequency' => 'hourly', 'starts_on' => '2026-01-01'],
        ['name' => 'Rent', 'transaction_type' => 'bill', 'frequency' => 'monthly', 'starts_on' => ''],
    ] as $badInput) {
        expect(fn () => $create->handle($badInput))
            ->toThrow(InvalidRecurringTransaction::class, 'Name, transaction type, supported frequency, and starts_on are required.');
    }
});

it('approves draft and pending_approval templates and rejects invalid states', function (): void {
    $create = new CreateRecurringTemplate();
    $approve = new ApproveRecurringTemplate();

    $draft = $create->handle([
        'name' => 'Weekly Cleaning',
        'transaction_type' => 'expense',
        'frequency' => 'weekly',
        'starts_on' => '2026-02-01',
    ]);

    $approved = $approve->handle($draft, 42);
    expect($approved->status)->toBe(RecurringStatus::Active)
        ->and($approved->approved_by)->toBe(42)
        ->and($approved->approved_at)->not->toBeNull();

    // Cannot approve an already active template
    expect(fn () => $approve->handle($approved, 42))
        ->toThrow(InvalidRecurringTransaction::class, 'Only draft recurring templates can be approved.');

    // PendingApproval can also be approved
    $pending = $create->handle([
        'name' => 'Quarterly Audit Retainer',
        'transaction_type' => 'journal',
        'frequency' => 'quarterly',
        'starts_on' => '2026-01-01',
    ]);
    $pending->update(['status' => RecurringStatus::PendingApproval]);

    $approvedPending = $approve->handle($pending->fresh());
    expect($approvedPending->status)->toBe(RecurringStatus::Active)
        ->and($approvedPending->approved_by)->toBeNull();
});

it('generates occurrences across frequencies, draft vs automatic, ends_on expiration, and safetyCap', function (): void {
    $create = new CreateRecurringTemplate();
    $approve = new ApproveRecurringTemplate();
    $generate = new GenerateRecurringOccurrences();

    // Non-active template returns 0
    $draft = $create->handle([
        'name' => 'Inactive Template',
        'transaction_type' => 'invoice',
        'frequency' => 'daily',
        'starts_on' => '2026-03-01',
    ]);
    expect($generate->handle($draft, '2026-03-05'))->toBe(0);

    // Automatic daily template with ends_on expires once cursor passes ends_on
    $daily = $approve->handle($create->handle([
        'name' => 'Daily Accrual',
        'transaction_type' => 'journal',
        'frequency' => 'daily',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-03',
        'automatic' => true,
        'payload' => ['amount' => 10],
    ]));

    $dailyCount = $generate->handle($daily, '2026-03-10');
    expect($dailyCount)->toBe(3)
        ->and($daily->fresh()->status)->toBe(RecurringStatus::Expired)
        ->and($daily->fresh()->next_run_on->toDateString())->toBe('2026-03-04')
        ->and($daily->occurrences()->count())->toBe(3);

    $firstOcc = $daily->occurrences()->orderBy('occurrence_on')->first();
    expect($firstOcc)->toBeInstanceOf(RecurringOccurrence::class)
        ->and($firstOcc->status)->toBe(OccurrenceStatus::Generated)
        ->and($firstOcc->generated_at)->not->toBeNull()
        ->and($firstOcc->generated_payload)->toBe(['amount' => 10]);

    // Manual (automatic=false) weekly template with safetyCap
    $weekly = $approve->handle($create->handle([
        'name' => 'Weekly Fee',
        'transaction_type' => 'bill',
        'frequency' => 'weekly',
        'starts_on' => '2026-04-01',
        'automatic' => false,
    ]));

    $weeklyCount = $generate->handle($weekly, '2026-05-31', 2);
    expect($weeklyCount)->toBe(2)
        ->and($weekly->fresh()->status)->toBe(RecurringStatus::Active)
        ->and($weekly->fresh()->next_run_on->toDateString())->toBe('2026-04-15')
        ->and($weekly->occurrences()->first()->status)->toBe(OccurrenceStatus::Draft)
        ->and($weekly->occurrences()->first()->generated_at)->toBeNull();

    // Monthly, quarterly, yearly progression
    $monthly = $approve->handle($create->handle([
        'name' => 'Monthly Sub',
        'transaction_type' => 'invoice',
        'frequency' => 'monthly',
        'starts_on' => '2026-01-15',
    ]));
    expect($generate->handle($monthly, '2026-02-15'))->toBe(2)
        ->and($monthly->fresh()->next_run_on->toDateString())->toBe('2026-03-15');

    $quarterly = $approve->handle($create->handle([
        'name' => 'Quarterly Tax',
        'transaction_type' => 'bill',
        'frequency' => 'quarterly',
        'starts_on' => '2026-01-01',
    ]));
    expect($generate->handle($quarterly, '2026-04-01'))->toBe(2)
        ->and($quarterly->fresh()->next_run_on->toDateString())->toBe('2026-07-01');

    $yearly = $approve->handle($create->handle([
        'name' => 'Annual License',
        'transaction_type' => 'bill',
        'frequency' => 'yearly',
        'starts_on' => '2026-01-01',
    ]));
    expect($generate->handle($yearly, '2027-01-01'))->toBe(2)
        ->and($yearly->fresh()->next_run_on->toDateString())->toBe('2028-01-01');
});

it('records a recurring exception and marks template failed when generation throws', function (): void {
    $create = new CreateRecurringTemplate();
    $approve = new ApproveRecurringTemplate();
    $generate = new GenerateRecurringOccurrences();

    $template = $approve->handle($create->handle([
        'name' => 'Broken Template',
        'transaction_type' => 'invoice',
        'frequency' => 'monthly',
        'starts_on' => '2026-06-01',
    ]));

    $listenerActive = true;
    RecurringOccurrence::creating(function (RecurringOccurrence $occurrence) use ($template, &$listenerActive): void {
        if ($listenerActive && $occurrence->template_id === $template->id) {
            $listenerActive = false;
            throw new \RuntimeException('Simulated occurrence write failure.');
        }
    });

    $generated = $generate->handle($template, '2026-06-01');

    expect($generated)->toBe(0)
        ->and($template->fresh()->status)->toBe(RecurringStatus::Failed);

    $exception = RecurringException::where('template_id', $template->id)->first();
    expect($exception)->toBeInstanceOf(RecurringException::class)
        ->and($exception->kind)->toBe('generation')
        ->and($exception->message)->toBe('Simulated occurrence write failure.')
        ->and($exception->status)->toBe('open');
});

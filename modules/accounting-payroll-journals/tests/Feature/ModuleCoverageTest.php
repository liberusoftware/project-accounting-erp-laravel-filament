<?php

declare(strict_types=1);

use Liberu\Accounting\PayrollJournals\Actions\CreatePayrollJournal;
use Liberu\Accounting\PayrollJournals\Actions\PostPayrollJournal;
use Liberu\Accounting\PayrollJournals\Actions\ReversePayrollJournal;
use Liberu\Accounting\PayrollJournals\Enums\JournalStatus;
use Liberu\Accounting\PayrollJournals\Exceptions\InvalidPayrollJournal;
use Liberu\Accounting\PayrollJournals\Models\PayrollJournal;
use Liberu\Accounting\PayrollJournals\PayrollJournalsServiceProvider;
use Liberu\Accounting\PayrollJournals\Queries\PayrollJournalSummary;

it('registers and boots the payroll journals service provider', function (): void {
    $provider = $this->app->register(PayrollJournalsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(PayrollJournalsServiceProvider::class);
});

it('exposes all payroll journal status enum cases', function (): void {
    expect(array_map(fn (JournalStatus $s): string => $s->value, JournalStatus::cases()))
        ->toBe(['draft', 'posted', 'reversed', 'corrected']);
});

it('validates, creates, posts, and reverses payroll journals', function (): void {
    $create = new CreatePayrollJournal();
    $post = new PostPayrollJournal();
    $reverse = new ReversePayrollJournal();

    expect(fn () => $create->handle([
        'journal_ref' => 'PJ-1',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'gross_pay' => -10,
    ]))->toThrow(InvalidPayrollJournal::class, 'Payroll journal amounts cannot be negative.');

    expect(fn () => $create->handle([
        'journal_ref' => 'PJ-1',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'gross_pay' => 1000,
        'taxes' => 200,
        'deductions' => 100,
        'net_pay' => 650,
    ]))->toThrow(InvalidPayrollJournal::class, 'Net pay must equal gross pay less taxes and deductions.');

    expect(fn () => $create->handle([
        'journal_ref' => '',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'gross_pay' => 1000,
    ]))->toThrow(InvalidPayrollJournal::class, 'Journal reference and positive gross pay are required.');

    $journal = $create->handle([
        'team_id' => 2,
        'journal_ref' => 'PJ-2026-01',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'currency' => 'GBP',
        'gross_pay' => 5000.00,
        'taxes' => 1000.00,
        'deductions' => 250.00,
        'benefits' => 150.00,
        'employer_costs' => 600.00,
        'liabilities' => ['hmrc' => 1000],
        'allocation' => ['eng' => 5000],
        'metadata' => ['run' => 1],
    ]);

    expect($journal)->toBeInstanceOf(PayrollJournal::class)
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and((float) $journal->net_pay)->toBe(3750.0);

    // Upsert with same team_id + journal_ref
    $updated = $create->handle([
        'team_id' => 2,
        'journal_ref' => 'PJ-2026-01',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'gross_pay' => 6000.00,
        'taxes' => 1200.00,
        'deductions' => 300.00,
        'employer_costs' => 700.00,
    ]);
    expect($updated->id)->toBe($journal->id)
        ->and((float) $updated->net_pay)->toBe(4500.0);

    // Cannot reverse draft journal
    expect(fn () => $reverse->handle($updated, 'REV-1'))
        ->toThrow(InvalidPayrollJournal::class, 'Only posted journals with a reversal reference can be reversed.');

    $posted = $post->handle($updated);
    expect($posted->status)->toBe(JournalStatus::Posted)
        ->and($posted->posted_at)->not->toBeNull();

    // Cannot post again
    expect(fn () => $post->handle($posted))
        ->toThrow(InvalidPayrollJournal::class, 'Only draft payroll journals can be posted.');

    // Cannot reverse with blank reversal ref
    expect(fn () => $reverse->handle($posted, '  '))
        ->toThrow(InvalidPayrollJournal::class, 'Only posted journals with a reversal reference can be reversed.');

    $reversed = $reverse->handle($posted, 'REV-2026-01');
    expect($reversed->status)->toBe(JournalStatus::Reversed)
        ->and($reversed->reversal_ref)->toBe('REV-2026-01')
        ->and($reversed->reversed_at)->not->toBeNull();
});

it('summarizes payroll journals for a team', function (): void {
    $summary = new PayrollJournalSummary();
    expect($summary->forTeam(77))->toBe([
        'count' => 0,
        'gross' => 0.0,
        'taxes' => 0.0,
        'deductions' => 0.0,
        'net_pay' => 0.0,
        'employer_costs' => 0.0,
        'posted' => 0,
        'reversed' => 0,
    ]);

    $create = new CreatePayrollJournal();
    $post = new PostPayrollJournal();
    $reverse = new ReversePayrollJournal();

    $j1 = $post->handle($create->handle([
        'team_id' => 7,
        'journal_ref' => 'J1',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'gross_pay' => 1000,
        'taxes' => 200,
        'deductions' => 50,
        'employer_costs' => 120,
    ]));

    $j2 = $reverse->handle($post->handle($create->handle([
        'team_id' => 7,
        'journal_ref' => 'J2',
        'payroll_period_start' => '2026-02-01',
        'payroll_period_end' => '2026-02-28',
        'gross_pay' => 2000,
        'taxes' => 400,
        'deductions' => 100,
        'employer_costs' => 240,
    ])), 'REV-J2');

    expect($j1->status)->toBe(JournalStatus::Posted)
        ->and($j2->status)->toBe(JournalStatus::Reversed);

    expect($summary->forTeam(7))->toBe([
        'count' => 2,
        'gross' => 3000.0,
        'taxes' => 600.0,
        'deductions' => 150.0,
        'net_pay' => 2250.0,
        'employer_costs' => 360.0,
        'posted' => 1,
        'reversed' => 1,
    ]);
});

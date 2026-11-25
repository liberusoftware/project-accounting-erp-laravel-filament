<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollJournals\Enums\JournalStatus;
use Liberu\Accounting\PayrollJournals\Models\PayrollJournal;
use Liberu\Accounting\PayrollJournalsLivewire\PayrollJournalsLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-payroll-journals-livewire components', function (): void {
    $provider = $this->app->register(PayrollJournalsLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $journal = PayrollJournal::query()->create([
        'team_id' => 1,
        'journal_ref' => 'PJ-001',
        'payroll_period_start' => '2026-01-01',
        'payroll_period_end' => '2026-01-31',
        'currency' => 'GBP',
        'gross_pay' => 1000,
        'taxes' => 200,
        'deductions' => 50,
        'benefits' => 0,
        'employer_costs' => 100,
        'net_pay' => 750,
        'status' => JournalStatus::Draft,
    ]);

    Livewire::test('module-accounting-payroll-journals-journals')
        ->call('selectJournal', (int) $journal->getKey())
        ->call('post')
        ->assertDispatched('payroll-journal-posted')
        ->call('selectJournal', (int) $journal->getKey())
        ->set('reversalRef', 'REV-001')
        ->call('reverse')
        ->assertDispatched('payroll-journal-reversed')
        ->assertOk();
});

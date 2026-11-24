<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\YearEnd\Actions\AddYearEndAdjustment;
use Liberu\Accounting\YearEnd\Actions\ArchiveYearEnd;
use Liberu\Accounting\YearEnd\Actions\CloseYearEnd;
use Liberu\Accounting\YearEnd\Actions\CreateYearEndClose;
use Liberu\Accounting\YearEnd\Actions\CreateYearEndPeriod;
use Liberu\Accounting\YearEnd\Actions\LockYearEnd;
use Liberu\Accounting\YearEnd\Actions\ReopenYearEnd;
use Liberu\Accounting\YearEnd\Enums\YearEndAdjustmentStatus;
use Liberu\Accounting\YearEnd\Enums\YearEndStatus;
use Liberu\Accounting\YearEnd\Exceptions\InvalidYearEnd;
use Liberu\Accounting\YearEnd\Models\YearEndAdjustment;
use Liberu\Accounting\YearEnd\Models\YearEndClose;
use Liberu\Accounting\YearEnd\Models\YearEndPeriod;
use Liberu\Accounting\YearEnd\Queries\YearEndQuery;
use Liberu\Accounting\YearEnd\YearEndServiceProvider;

it('boots the year end service provider and loads migrations', function (): void {
    $provider = $this->app->register(YearEndServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(YearEndServiceProvider::class)
        ->and(Schema::hasTable('accounting_year_end_closes'))->toBeTrue()
        ->and(Schema::hasTable('accounting_year_end_periods'))->toBeTrue()
        ->and(Schema::hasTable('accounting_year_end_adjustments'))->toBeTrue();
});

it('exposes year end enums and exception', function (): void {
    expect(YearEndStatus::cases())->toHaveCount(6)
        ->and(YearEndStatus::Open->value)->toBe('open')
        ->and(YearEndStatus::Adjusted->value)->toBe('adjusted')
        ->and(YearEndStatus::Locked->value)->toBe('locked')
        ->and(YearEndStatus::Archived->value)->toBe('archived')
        ->and(YearEndStatus::Closed->value)->toBe('closed')
        ->and(YearEndStatus::Reopened->value)->toBe('reopened')
        ->and(YearEndAdjustmentStatus::cases())->toHaveCount(2)
        ->and(YearEndAdjustmentStatus::Draft->value)->toBe('draft')
        ->and(YearEndAdjustmentStatus::Posted->value)->toBe('posted');

    $exception = new InvalidYearEnd('Invalid year end');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid year end');
});

it('creates year end periods, adds adjustments, locks, archives, and queries by team', function (): void {
    $createPeriod = new CreateYearEndPeriod();

    foreach (['team_id', 'period_ref', 'period_end'] as $missing) {
        $payload = ['team_id' => 3, 'period_ref' => 'FY2026', 'period_end' => '2026-12-31'];
        unset($payload[$missing]);
        expect(fn () => $createPeriod->handle($payload))
            ->toThrow(InvalidYearEnd::class, "{$missing} is required.");
    }

    $olderPeriod = $createPeriod->handle([
        'team_id' => 3,
        'period_ref' => 'FY2025',
        'period_end' => '2025-12-31',
        'opening_balances' => ['1000' => 5000],
        'statutory_handoff' => ['filings' => 'ready'],
        'evidence' => ['signoff' => 'cfo'],
    ]);
    $period = $createPeriod->handle([
        'team_id' => 3,
        'period_ref' => 'FY2026',
        'period_end' => '2026-12-31',
    ]);
    $otherTeamPeriod = $createPeriod->handle([
        'team_id' => 99,
        'period_ref' => 'FY2026',
        'period_end' => '2026-12-31',
    ]);

    expect($olderPeriod)->toBeInstanceOf(YearEndPeriod::class)
        ->and($olderPeriod->status)->toBe(YearEndStatus::Open)
        ->and($olderPeriod->period_end)->toBeInstanceOf(Carbon::class)
        ->and($olderPeriod->opening_balances)->toBe(['1000' => 5000])
        ->and($olderPeriod->statutory_handoff)->toBe(['filings' => 'ready'])
        ->and($olderPeriod->evidence)->toBe(['signoff' => 'cfo']);

    $addAdj = new AddYearEndAdjustment();

    expect(fn () => $addAdj->handle($period, [
        'adjustment_ref' => 'ADJ-0',
        'description' => 'Zero',
        'amount' => 0,
    ]))->toThrow(InvalidYearEnd::class, 'Locked periods cannot receive zero-value adjustments.');

    expect(fn () => $addAdj->handle($period, [
        'adjustment_ref' => '',
        'description' => 'Accrual',
        'amount' => 100,
    ]))->toThrow(InvalidYearEnd::class, 'adjustment_ref is required.');

    expect(fn () => $addAdj->handle($period, [
        'adjustment_ref' => 'ADJ-1',
        'description' => '',
        'amount' => 100,
    ]))->toThrow(InvalidYearEnd::class, 'description is required.');

    $adj1 = $addAdj->handle($period, [
        'adjustment_ref' => 'ADJ-1',
        'description' => 'Year-end accrual',
        'amount' => 1250.50,
        'journal_ref' => 'JRN-101',
        'evidence' => ['memo' => 'accrual'],
    ]);
    $adj2 = $addAdj->handle($period, [
        'adjustment_ref' => 'ADJ-2',
        'description' => 'Depreciation',
        'amount' => -250.50,
    ]);

    expect($adj1)->toBeInstanceOf(YearEndAdjustment::class)
        ->and($adj1->status)->toBe(YearEndAdjustmentStatus::Draft)
        ->and($adj1->evidence)->toBe(['memo' => 'accrual'])
        ->and($adj1->period->is($period))->toBeTrue()
        ->and($period->fresh()->status)->toBe(YearEndStatus::Adjusted);

    $archive = new ArchiveYearEnd();
    expect(fn () => $archive->handle($period->fresh()))
        ->toThrow(InvalidYearEnd::class, 'Only locked year ends can be archived.');

    $lock = new LockYearEnd();
    $locked = $lock->handle($period->fresh(), 42);
    expect($locked->status)->toBe(YearEndStatus::Locked)
        ->and((float) $locked->retained_earnings)->toBe(1000.0)
        ->and($locked->locked_by)->toBe(42)
        ->and($locked->locked_at)->toBeInstanceOf(Carbon::class)
        ->and($locked->adjustments)->toHaveCount(2);

    expect(fn () => $lock->handle($locked, 42))
        ->toThrow(InvalidYearEnd::class, 'Year end is already closed.');

    expect(fn () => $addAdj->handle($locked, [
        'adjustment_ref' => 'ADJ-3',
        'description' => 'Late entry',
        'amount' => 50,
    ]))->toThrow(InvalidYearEnd::class, 'Locked periods cannot receive zero-value adjustments.');

    $archived = $archive->handle($locked);
    expect($archived->status)->toBe(YearEndStatus::Archived)
        ->and($archived->archived_at)->toBeInstanceOf(Carbon::class);

    expect(fn () => $lock->handle($archived, 42))
        ->toThrow(InvalidYearEnd::class, 'Year end is already closed.');

    expect(fn () => $addAdj->handle($archived, [
        'adjustment_ref' => 'ADJ-4',
        'description' => 'Archived entry',
        'amount' => 50,
    ]))->toThrow(InvalidYearEnd::class, 'Locked periods cannot receive zero-value adjustments.');

    $query = new YearEndQuery();
    expect($query->forTeam(9999))->toBeEmpty();

    $teamPeriods = $query->forTeam(3);
    expect($teamPeriods)->toHaveCount(2)
        ->and($teamPeriods->first()->is($period))->toBeTrue()
        ->and($teamPeriods->first()->relationLoaded('adjustments'))->toBeTrue()
        ->and($teamPeriods->first()->adjustments)->toHaveCount(2)
        ->and($otherTeamPeriod->team_id)->toBe(99);
});

it('manages year end close lifecycle including close, reopen, and validation', function (): void {
    $createClose = new CreateYearEndClose();

    expect(fn () => $createClose->handle([
        'team_id' => 1,
        'fiscal_year' => 1999,
        'period_end' => '1999-12-31',
        'retained_earnings_account_ref' => '3100',
    ]))->toThrow(InvalidYearEnd::class, 'A fiscal year, period end, and retained earnings account are required.');

    expect(fn () => $createClose->handle([
        'team_id' => 1,
        'fiscal_year' => 2026,
        'period_end' => '',
        'retained_earnings_account_ref' => '3100',
    ]))->toThrow(InvalidYearEnd::class, 'A fiscal year, period end, and retained earnings account are required.');

    expect(fn () => $createClose->handle([
        'team_id' => 1,
        'fiscal_year' => 2026,
        'period_end' => '2026-12-31',
        'retained_earnings_account_ref' => '',
    ]))->toThrow(InvalidYearEnd::class, 'A fiscal year, period end, and retained earnings account are required.');

    $closeRecord = $createClose->handle([
        'team_id' => 5,
        'fiscal_year' => 2026,
        'period_end' => '2026-12-31',
        'retained_earnings_account_ref' => '3100',
        'net_income' => 45250.75,
        'metadata' => ['auditor' => 'Jane Doe'],
    ]);

    expect($closeRecord)->toBeInstanceOf(YearEndClose::class)
        ->and($closeRecord->status)->toBe(YearEndStatus::Open)
        ->and($closeRecord->period_end)->toBeInstanceOf(Carbon::class)
        ->and((float) $closeRecord->net_income)->toBe(45250.75)
        ->and($closeRecord->metadata)->toBe(['auditor' => 'Jane Doe']);

    $closeAction = new CloseYearEnd();
    $reopenAction = new ReopenYearEnd();

    expect(fn () => $reopenAction->handle($closeRecord))
        ->toThrow(InvalidYearEnd::class, 'Only closed year ends can be reopened.');

    $closed = $closeAction->handle($closeRecord, 'CLOSE-2026-01');
    expect($closed->status)->toBe(YearEndStatus::Closed)
        ->and($closed->closing_entry_ref)->toBe('CLOSE-2026-01')
        ->and($closed->closed_at)->toBeInstanceOf(Carbon::class);

    expect(fn () => $closeAction->handle($closed, 'CLOSE-2026-02'))
        ->toThrow(InvalidYearEnd::class, 'Only open or reopened year ends can be closed.');

    $reopened = $reopenAction->handle($closed);
    expect($reopened->status)->toBe(YearEndStatus::Reopened);

    $reclosed = $closeAction->handle($reopened, 'CLOSE-2026-03');
    expect($reclosed->status)->toBe(YearEndStatus::Closed)
        ->and($reclosed->closing_entry_ref)->toBe('CLOSE-2026-03');

    $lockedClose = $createClose->handle([
        'team_id' => 6,
        'fiscal_year' => 2025,
        'period_end' => '2025-12-31',
        'retained_earnings_account_ref' => '3100',
        'status' => YearEndStatus::Locked,
    ]);

    expect(fn () => $reopenAction->handle($lockedClose))
        ->toThrow(InvalidYearEnd::class, 'Locked year ends require an authorized administrative override.');
});

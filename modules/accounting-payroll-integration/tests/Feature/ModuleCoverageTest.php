<?php

declare(strict_types=1);

use Liberu\Accounting\PayrollIntegration\Actions\ImportPayrollRun;
use Liberu\Accounting\PayrollIntegration\Actions\MarkPayrollImport;
use Liberu\Accounting\PayrollIntegration\Contracts\PayrollProviderAdapter;
use Liberu\Accounting\PayrollIntegration\Enums\ImportStatus;
use Liberu\Accounting\PayrollIntegration\Exceptions\InvalidPayrollImport;
use Liberu\Accounting\PayrollIntegration\Models\PayrollImport;
use Liberu\Accounting\PayrollIntegration\PayrollIntegrationServiceProvider;
use Liberu\Accounting\PayrollIntegration\Queries\PayrollImportSummary;

it('registers and boots the payroll integration service provider', function (): void {
    $provider = $this->app->register(PayrollIntegrationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(PayrollIntegrationServiceProvider::class);
});

it('exposes all payroll import status enum cases and contract interface', function (): void {
    expect(array_map(fn (ImportStatus $s): string => $s->value, ImportStatus::cases()))
        ->toBe(['received', 'validated', 'imported', 'failed', 'reconciled']);

    $adapter = new class() implements PayrollProviderAdapter
    {
        public function validateImport(array $payload): array
        {
            return ['valid' => true, 'count' => count($payload)];
        }

        public function import(array $payload): array
        {
            return ['imported' => true, 'payload' => $payload];
        }
    };

    expect($adapter->validateImport(['a' => 1]))->toBe(['valid' => true, 'count' => 1])
        ->and($adapter->import(['a' => 1]))->toBe(['imported' => true, 'payload' => ['a' => 1]]);
});

it('validates, imports, and transitions payroll runs across all status branches', function (): void {
    $importAction = new ImportPayrollRun();
    $markAction = new MarkPayrollImport();

    expect(fn () => $importAction->handle([
        'provider' => '',
        'run_ref' => 'run-1',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]))->toThrow(InvalidPayrollImport::class, 'Provider, period, and run identity are required.');

    expect(fn () => $importAction->handle([
        'provider' => 'gusto',
        'run_ref' => 'run-1',
        'period_start' => '2026-02-10',
        'period_end' => '2026-02-01',
    ]))->toThrow(InvalidPayrollImport::class, 'Payroll period is invalid.');

    // Invalid payload types produce Failed import with validation_errors
    $failed = $importAction->handle([
        'team_id' => 1,
        'provider' => 'gusto',
        'run_ref' => 'run-bad',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'employee_refs' => 'not-an-array',
        'contractor_refs' => 'also-not-array',
        'dimensions' => 'bad',
    ]);

    expect($failed->status)->toBe(ImportStatus::Failed)
        ->and($failed->validation_errors)->toContain(
            'At least one employee or contractor reference is required.',
            'employee_refs must be an array.',
            'contractor_refs must be an array.',
            'dimensions must be an array.',
        );

    expect(fn () => $markAction->handle($failed, ImportStatus::Imported))
        ->toThrow(InvalidPayrollImport::class, 'Only validated imports can be marked imported.');

    $validated = $importAction->handle([
        'team_id' => 1,
        'provider' => 'gusto',
        'run_ref' => 'run-good',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'GBP',
        'employee_refs' => ['emp-1', 'emp-2'],
        'contractor_refs' => [],
        'dimensions' => ['dept' => 'eng'],
        'project_refs' => ['proj-1'],
        'metadata' => ['batch' => 1],
    ]);

    expect($validated)->toBeInstanceOf(PayrollImport::class)
        ->and($validated->status)->toBe(ImportStatus::Validated)
        ->and($validated->validation_errors)->toBe([])
        ->and($validated->imported_at)->toBeNull();

    // Upsert on same team_id + provider + run_ref
    $updated = $importAction->handle([
        'team_id' => 1,
        'provider' => 'gusto',
        'run_ref' => 'run-good',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'employee_refs' => ['emp-1', 'emp-2', 'emp-3'],
    ]);
    expect($updated->id)->toBe($validated->id)
        ->and($updated->employee_refs)->toHaveCount(3);

    expect(fn () => $markAction->handle($updated, ImportStatus::Reconciled))
        ->toThrow(InvalidPayrollImport::class, 'Only imported runs can be reconciled.');

    $imported = $markAction->handle($updated, ImportStatus::Imported);
    expect($imported->status)->toBe(ImportStatus::Imported)
        ->and($imported->imported_at)->not->toBeNull()
        ->and($imported->reconciled_at)->toBeNull();

    $reconciled = $markAction->handle($imported, ImportStatus::Reconciled);
    expect($reconciled->status)->toBe(ImportStatus::Reconciled)
        ->and($reconciled->reconciled_at)->not->toBeNull();
});

it('summarizes payroll imports per team', function (): void {
    $summary = new PayrollImportSummary();
    expect($summary->forTeam(99))->toBe([
        'count' => 0,
        'providers' => [],
        'validated' => 0,
        'failed' => 0,
        'imported' => 0,
        'reconciled' => 0,
    ]);

    $import = new ImportPayrollRun();
    $mark = new MarkPayrollImport();

    $r1 = $import->handle(['team_id' => 9, 'provider' => 'adp', 'run_ref' => 'a1', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
    $r2 = $import->handle(['team_id' => 9, 'provider' => 'adp', 'run_ref' => 'a2', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28']);
    $r3 = $import->handle(['team_id' => 9, 'provider' => 'xero', 'run_ref' => 'x1', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'employee_refs' => 'bad', 'contractor_refs' => 'bad']);
    $r4 = $import->handle(['team_id' => 9, 'provider' => 'xero', 'run_ref' => 'x2', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30']);

    $r2 = $mark->handle($r2, ImportStatus::Imported);
    $mark->handle($r2, ImportStatus::Reconciled);
    $mark->handle($r4, ImportStatus::Imported);

    expect($summary->forTeam(9))->toBe([
        'count' => 4,
        'providers' => ['adp' => 2, 'xero' => 2],
        'validated' => 1,
        'failed' => 1,
        'imported' => 1,
        'reconciled' => 1,
    ]);
});
